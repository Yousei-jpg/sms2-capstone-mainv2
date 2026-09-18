"""Google OR-Tools CP-SAT solver for the Exam Timetable Generator.

Input is a JSON file path. Output is one JSON object on stdout so the PHP
bridge can report failures without parsing tracebacks.
"""

from __future__ import annotations

import json
import sys
from collections import defaultdict


def output(payload: dict) -> None:
    print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))


def main() -> None:
    if len(sys.argv) != 2:
        output({"status": "ERROR", "message": "A scheduling problem file is required."})
        return

    try:
        from ortools.sat.python import cp_model
    except Exception:
        output({
            "status": "ERROR",
            "message": "Google OR-Tools is not installed for the configured Python interpreter.",
        })
        return

    try:
        with open(sys.argv[1], "r", encoding="utf-8") as handle:
            problem = json.load(handle)

        exams = problem.get("exams", [])
        slots = problem.get("slots", [])
        rooms = problem.get("rooms", [])
        blocked = {tuple(map(int, pair)) for pair in problem.get("blocked", [])}
        occupied = {tuple(map(int, pair)) for pair in problem.get("occupied", [])}
        max_per_day = max(1, int(problem.get("max_per_section_per_day", 2)))
        if not exams or not slots or not rooms:
            output({"status": "INFEASIBLE", "message": "Exams, slots, and rooms are required."})
            return

        model = cp_model.CpModel()
        variables = {}
        by_exam = defaultdict(list)
        by_slot_room = defaultdict(list)
        by_section_slot = defaultdict(list)
        by_proctor_slot = defaultdict(list)
        by_section_day = defaultdict(list)

        for exam in exams:
            exam_id = int(exam["id"])
            section_id = int(exam["section_id"])
            proctor_id = exam.get("proctor_id")
            students = int(exam.get("students", 0))
            for slot in slots:
                slot_index = int(slot["index"])
                if (exam_id, slot_index) in blocked:
                    continue
                for room in rooms:
                    room_id = int(room["id"])
                    if int(room.get("capacity", 0)) < students or (slot_index, room_id) in occupied:
                        continue
                    key = (exam_id, slot_index, room_id)
                    var = model.NewBoolVar(f"x_{exam_id}_{slot_index}_{room_id}")
                    variables[key] = var
                    by_exam[exam_id].append(var)
                    by_slot_room[(slot_index, room_id)].append(var)
                    by_section_slot[(section_id, slot_index)].append(var)
                    if proctor_id is not None:
                        by_proctor_slot[(int(proctor_id), slot_index)].append(var)
                    by_section_day[(section_id, str(slot["date"]))].append(var)

        for exam in exams:
            choices = by_exam[int(exam["id"])]
            if not choices:
                output({
                    "status": "INFEASIBLE",
                    "message": f"No feasible slot and room exist for exam {exam['id']}.",
                })
                return
            model.AddExactlyOne(choices)

        for group in by_slot_room.values():
            model.AddAtMostOne(group)
        for group in by_section_slot.values():
            model.AddAtMostOne(group)
        for group in by_proctor_slot.values():
            model.AddAtMostOne(group)
        for group in by_section_day.values():
            model.Add(sum(group) <= max_per_day)

        # Prefer earlier dates/periods, then smaller suitable rooms. This keeps
        # the result deterministic and leaves larger rooms for larger cohorts.
        objective = []
        room_rank = {int(room["id"]): rank for rank, room in enumerate(
            sorted(rooms, key=lambda value: (int(value.get("capacity", 0)), int(value["id"])))
        )}
        for (_, slot_index, room_id), var in variables.items():
            objective.append((slot_index * 100 + room_rank[room_id]) * var)
        model.Minimize(sum(objective))

        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = float(problem.get("time_limit_seconds", 15))
        solver.parameters.num_search_workers = 8
        status = solver.Solve(model)
        if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            output({"status": "INFEASIBLE", "message": "No conflict-free timetable satisfies all constraints."})
            return

        assignments = []
        for (exam_id, slot_index, room_id), var in variables.items():
            if solver.Value(var):
                assignments.append({
                    "exam_id": exam_id,
                    "slot_index": slot_index,
                    "room_id": room_id,
                })
        assignments.sort(key=lambda value: value["exam_id"])
        output({
            "status": "OPTIMAL" if status == cp_model.OPTIMAL else "FEASIBLE",
            "assignments": assignments,
            "stats": {
                "wall_time": round(solver.WallTime(), 4),
                "conflicts": solver.NumConflicts(),
                "branches": solver.NumBranches(),
            },
        })
    except Exception as exc:
        output({"status": "ERROR", "message": f"The optimization model failed: {exc}"})


if __name__ == "__main__":
    main()
