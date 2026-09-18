#!/usr/bin/env python3
"""
SMS 2 - Auto Section Assignment solver
Module: Enrollment  |  Subsystem: Student Assignment -> Auto Section Assignment

Reads a JSON problem on stdin, writes a JSON solution on stdout. It touches no
database and holds no SMS 2 knowledge: PHP owns eligibility, persistence, and
audit. This process only answers "which student goes in which section".

Keeping it that way means the model can be tested on its own, and a solver
failure can never leave half-written rows in the database.

=============================================================================
THE MODEL  (stated explicitly, as the module specification requires)

Decision variable
    x[s][c] = 1 if student s is placed in section c, else 0.
    Variables exist ONLY for pairs that are already feasible, so an impossible
    placement cannot be chosen at all rather than being penalised later.

Hard constraints
    C1  Each student is placed at most once.
            for every s:  sum over c of x[s][c] <= 1
        "At most" rather than "exactly": a student with no feasible section
        must be reportable as an exception, not make the whole model
        infeasible. One unplaceable student should never block the other
        four hundred.

    C2  No section exceeds its remaining capacity.
            for every c:  sum over s of x[s][c] <= remaining_capacity[c]
        remaining_capacity is current enrolment subtracted by PHP immediately
        before solving, so a section that filled up mid-run is respected.

    C3  Feasibility, enforced by which variables are created:
            program match, year level match, term match, section is active,
            and any section-level restriction the caller passed.

Objective  (maximised, in priority order by weight)
    O1  Placement            W_PLACE    = 1000 per student placed
        Placing a student is worth more than any preference, so the solver
        never leaves someone unassigned to make the distribution tidier.

    O2  Preferred section    W_PREFER   = 50 when honoured
        A real but secondary reward.

    O3  Priority order       W_PRIORITY = 0..20, scaled from the student's
        priority rank. When two students compete for one seat, the earlier
        applicant wins. Bounded well under W_PREFER so it breaks ties rather
        than dominating.

    O4  Balanced fill        W_BALANCE  penalises the spread between the
        fullest and emptiest section of a group, so students spread out
        instead of packing the first eligible section.

    Weights are separated by an order of magnitude at each level, so a lower
    objective can never outweigh a higher one by accumulating.
=============================================================================

Usage:
    python assign_sections.py < problem.json > solution.json
    python assign_sections.py --selftest
"""

import json
import sys

W_PLACE = 1000
W_PREFER = 50
W_PRIORITY_MAX = 20
W_BALANCE = 5

SOLVER_VERSION = "1.0.0"


def fail(message, code="solver_error"):
    """Always emit JSON. PHP should never have to parse a Python traceback."""
    json.dump({
        "ok": False,
        "error_code": code,
        "error": message,
        "assignments": [],
        "unassigned": [],
        "solver_version": SOLVER_VERSION,
    }, sys.stdout)
    sys.stdout.write("\n")
    sys.exit(0)


def solve(problem):
    try:
        from ortools.sat.python import cp_model
    except ImportError:
        fail(
            "The OR-Tools library is not installed for this Python interpreter. "
            "Install it with: pip install ortools",
            code="ortools_missing",
        )

    students = problem.get("students") or []
    sections = problem.get("sections") or []
    options = problem.get("options") or {}

    if not students:
        return {
            "ok": True,
            "assignments": [],
            "unassigned": [],
            "stats": {"students": 0, "sections": len(sections), "placed": 0},
            "solver_version": SOLVER_VERSION,
            "notes": ["No eligible students were supplied, so there was nothing to assign."],
        }

    if not sections:
        return {
            "ok": True,
            "assignments": [],
            "unassigned": [
                {"student_id": s["id"], "reason": "no_sections",
                 "detail": "No active section exists for this program, year level, and term."}
                for s in students
            ],
            "stats": {"students": len(students), "sections": 0, "placed": 0},
            "solver_version": SOLVER_VERSION,
            "notes": ["No sections were supplied, so every student is an exception."],
        }

    respect_preference = bool(options.get("respect_preference", True))
    balance = bool(options.get("balance_distribution", True))

    model = cp_model.Model() if hasattr(cp_model, "Model") else cp_model.CpModel()

    section_by_id = {c["id"]: c for c in sections}

    # --- C3: only build variables for feasible pairs -------------------------
    x = {}
    infeasible_reason = {}

    for s in students:
        options_for_student = []

        for c in sections:
            if not c.get("is_active", True):
                continue
            if c.get("program_id") is not None and s.get("program_id") is not None \
                    and c["program_id"] != s["program_id"]:
                continue
            if c.get("year_level") is not None and s.get("year_level") is not None \
                    and str(c["year_level"]) != str(s["year_level"]):
                continue
            if c.get("term_id") is not None and s.get("term_id") is not None \
                    and c["term_id"] != s["term_id"]:
                continue
            if int(c.get("remaining_capacity", 0)) <= 0:
                continue
            if c["id"] in (s.get("excluded_section_ids") or []):
                continue

            options_for_student.append(c["id"])

        if not options_for_student:
            # Diagnose WHY, so PHP can show a specific exception rather than
            # "could not assign". The order matters: report the most specific
            # cause first.
            same_program = [c for c in sections
                            if c.get("program_id") == s.get("program_id")]
            if not same_program:
                infeasible_reason[s["id"]] = ("program_mismatch",
                                              "No section exists for this student's program.")
            else:
                same_level = [c for c in same_program
                              if str(c.get("year_level")) == str(s.get("year_level"))]
                if not same_level:
                    infeasible_reason[s["id"]] = ("year_level_mismatch",
                                                  "No section exists for this program at this year level.")
                elif all(int(c.get("remaining_capacity", 0)) <= 0 for c in same_level):
                    infeasible_reason[s["id"]] = ("full_section",
                                                  "Every eligible section is already at full capacity.")
                else:
                    infeasible_reason[s["id"]] = ("restriction",
                                                  "A restriction excluded every otherwise eligible section.")
            continue

        for cid in options_for_student:
            x[(s["id"], cid)] = model.new_bool_var(f"x_{s['id']}_{cid}")

    if not x:
        return {
            "ok": True,
            "assignments": [],
            "unassigned": [
                {"student_id": sid, "reason": r[0], "detail": r[1]}
                for sid, r in infeasible_reason.items()
            ],
            "stats": {"students": len(students), "sections": len(sections), "placed": 0},
            "solver_version": SOLVER_VERSION,
            "notes": ["No student had a feasible section."],
        }

    # --- C1: each student placed at most once --------------------------------
    for s in students:
        vars_for_student = [x[(s["id"], c["id"])] for c in sections
                            if (s["id"], c["id"]) in x]
        if vars_for_student:
            model.add(sum(vars_for_student) <= 1)

    # --- C2: capacity --------------------------------------------------------
    fill = {}
    for c in sections:
        vars_for_section = [x[(s["id"], c["id"])] for s in students
                            if (s["id"], c["id"]) in x]
        if not vars_for_section:
            continue
        cap = int(c.get("remaining_capacity", 0))
        model.add(sum(vars_for_section) <= cap)
        fill[c["id"]] = sum(vars_for_section)

    # --- Objective -----------------------------------------------------------
    terms = []
    total = len(students)

    for index, s in enumerate(students):
        # Priority rank arrives pre-sorted from PHP: earliest first.
        rank = s.get("priority_rank", index)
        priority_weight = int(W_PRIORITY_MAX * (total - rank) / total) if total else 0

        for c in sections:
            key = (s["id"], c["id"])
            if key not in x:
                continue

            weight = W_PLACE + priority_weight

            if respect_preference and s.get("preferred_section_id") == c["id"]:
                weight += W_PREFER

            terms.append(weight * x[key])

    # --- O4: balance ---------------------------------------------------------
    if balance and len(fill) > 1:
        caps = [int(c.get("remaining_capacity", 0)) for c in sections if c["id"] in fill]
        upper = max(caps) if caps else 0
        if upper > 0:
            spread_hi = model.new_int_var(0, upper, "fill_max")
            spread_lo = model.new_int_var(0, upper, "fill_min")
            for cid, expr in fill.items():
                model.add(spread_hi >= expr)
                model.add(spread_lo <= expr)
            # Penalising the gap pushes students to spread out. It is weighted
            # far below W_PLACE, so balance never costs anyone a placement.
            terms.append(-W_BALANCE * (spread_hi - spread_lo))

    model.maximize(sum(terms))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(options.get("time_limit_seconds", 30))
    solver.parameters.num_search_workers = 8

    status = solver.solve(model)

    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        fail(
            "The solver could not find any valid assignment. This usually means the "
            "constraints conflict, for example every section is full. Status: "
            + solver.status_name(status),
            code="no_solution",
        )

    assignments = []
    placed_ids = set()

    for s in students:
        for c in sections:
            key = (s["id"], c["id"])
            if key in x and solver.value(x[key]) == 1:
                assignments.append({
                    "student_id": s["id"],
                    "section_id": c["id"],
                    "section_code": section_by_id[c["id"]].get("code"),
                    "was_preferred": s.get("preferred_section_id") == c["id"],
                })
                placed_ids.add(s["id"])
                break

    unassigned = []
    for s in students:
        if s["id"] in placed_ids:
            continue
        reason, detail = infeasible_reason.get(
            s["id"],
            ("capacity_exhausted",
             "A section was eligible but every seat was taken by a higher-priority student."),
        )
        unassigned.append({"student_id": s["id"], "reason": reason, "detail": detail})

    return {
        "ok": True,
        "assignments": assignments,
        "unassigned": unassigned,
        "stats": {
            "students": len(students),
            "sections": len(sections),
            "placed": len(assignments),
            "exceptions": len(unassigned),
            "preferred_honoured": sum(1 for a in assignments if a["was_preferred"]),
            "objective": int(solver.objective_value),
            "status": solver.status_name(status),
            "wall_time_seconds": round(solver.wall_time, 3),
        },
        "solver_version": SOLVER_VERSION,
        "notes": [],
    }


def selftest():
    """Run the model against known cases so the objective ordering is proven,
    not assumed. Every case asserts a property the PHP side relies on."""
    results = []

    # 1. Capacity is never exceeded, and the overflow student is an exception.
    problem = {
        "students": [
            {"id": i, "program_id": 1, "year_level": "1st Year", "term_id": 1,
             "priority_rank": i}
            for i in range(5)
        ],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 3, "is_active": True},
        ],
    }
    r = solve(problem)
    assert len(r["assignments"]) == 3, r["stats"]
    assert len(r["unassigned"]) == 2
    assert r["unassigned"][0]["reason"] == "capacity_exhausted"
    results.append("C2 capacity respected: 3 placed, 2 exceptions")

    # 2. Priority wins the scarce seat.
    problem = {
        "students": [
            {"id": 1, "program_id": 1, "year_level": "1st Year", "term_id": 1, "priority_rank": 0},
            {"id": 2, "program_id": 1, "year_level": "1st Year", "term_id": 1, "priority_rank": 1},
        ],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 1, "is_active": True},
        ],
    }
    r = solve(problem)
    assert r["assignments"][0]["student_id"] == 1, r["assignments"]
    results.append("O3 priority honoured: earlier applicant took the last seat")

    # 3. Program mismatch is diagnosed specifically.
    problem = {
        "students": [{"id": 1, "program_id": 99, "year_level": "1st Year", "term_id": 1}],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 40, "is_active": True},
        ],
    }
    r = solve(problem)
    assert r["unassigned"][0]["reason"] == "program_mismatch", r["unassigned"]
    results.append("C3 program mismatch diagnosed, not lumped into a generic failure")

    # 4. Year level mismatch is distinguished from program mismatch.
    problem = {
        "students": [{"id": 1, "program_id": 1, "year_level": "3rd Year", "term_id": 1}],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 40, "is_active": True},
        ],
    }
    r = solve(problem)
    assert r["unassigned"][0]["reason"] == "year_level_mismatch", r["unassigned"]
    results.append("C3 year level mismatch diagnosed separately")

    # 5. Placement beats preference: the student takes a non-preferred section
    #    rather than going unplaced.
    problem = {
        "students": [
            {"id": 1, "program_id": 1, "year_level": "1st Year", "term_id": 1,
             "preferred_section_id": 11},
        ],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 5, "is_active": True},
            {"id": 11, "code": "BSIT-1B", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 0, "is_active": True},
        ],
    }
    r = solve(problem)
    assert len(r["assignments"]) == 1
    assert r["assignments"][0]["section_id"] == 10
    results.append("O1 beats O2: placed in a non-preferred section rather than left out")

    # 6. Preference honoured when it costs nothing.
    problem = {
        "students": [
            {"id": 1, "program_id": 1, "year_level": "1st Year", "term_id": 1,
             "preferred_section_id": 11},
        ],
        "sections": [
            {"id": 10, "code": "BSIT-1A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 5, "is_active": True},
            {"id": 11, "code": "BSIT-1B", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 5, "is_active": True},
        ],
    }
    r = solve(problem)
    assert r["assignments"][0]["section_id"] == 11, r["assignments"]
    results.append("O2 preference honoured when capacity allows")

    # 7. Balance spreads students instead of packing the first section.
    problem = {
        "students": [
            {"id": i, "program_id": 1, "year_level": "1st Year", "term_id": 1}
            for i in range(20)
        ],
        "sections": [
            {"id": 10, "code": "A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 40, "is_active": True},
            {"id": 11, "code": "B", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 40, "is_active": True},
        ],
        "options": {"balance_distribution": True},
    }
    r = solve(problem)
    counts = {}
    for a in r["assignments"]:
        counts[a["section_id"]] = counts.get(a["section_id"], 0) + 1
    assert len(r["assignments"]) == 20
    assert abs(counts.get(10, 0) - counts.get(11, 0)) <= 1, counts
    results.append(f"O4 balanced: {counts.get(10,0)}/{counts.get(11,0)} across two sections")

    # 8. Inactive sections are never used.
    problem = {
        "students": [{"id": 1, "program_id": 1, "year_level": "1st Year", "term_id": 1}],
        "sections": [
            {"id": 10, "code": "A", "program_id": 1, "year_level": "1st Year",
             "term_id": 1, "remaining_capacity": 40, "is_active": False},
        ],
    }
    r = solve(problem)
    assert len(r["assignments"]) == 0, r["assignments"]
    results.append("C3 inactive sections excluded")

    # 9. Empty input is handled, not crashed on.
    r = solve({"students": [], "sections": []})
    assert r["ok"] and r["assignments"] == []
    results.append("empty input handled without error")

    for line in results:
        print("  PASS  " + line)
    print(f"\n{len(results)} solver properties verified.")


def main():
    if "--selftest" in sys.argv:
        selftest()
        return

    raw = sys.stdin.read()
    if not raw.strip():
        fail("No problem was received on stdin.", code="empty_input")

    try:
        problem = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail(f"The problem sent to the solver was not valid JSON: {exc}", code="bad_json")

    try:
        solution = solve(problem)
    except Exception as exc:  # noqa: BLE001 - PHP must always get JSON back
        fail(f"The solver failed unexpectedly: {exc}")

    json.dump(solution, sys.stdout)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
