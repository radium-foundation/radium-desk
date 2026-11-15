#!/usr/bin/env python3
"""Signal one process group created by this run, then exit. Never scans the system."""

import os
import signal
import sys
import time


def alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    return True


def main() -> int:
    if len(sys.argv) != 5:
        print("usage: p1010-bounded-timer.py CAP GRACE REASON_FILE LEADER", file=sys.stderr)
        return 2
    cap = int(sys.argv[1])
    grace = int(sys.argv[2])
    reason_file = sys.argv[3]
    leader = int(sys.argv[4])
    if leader <= 1 or cap < 1 or grace < 1:
        print("refusing unsafe timeout arguments", file=sys.stderr)
        return 2
    time.sleep(cap)
    if not alive(leader):
        return 0
    with open(reason_file, "w", encoding="utf-8") as handle:
        handle.write("wall_clock_cap\n")
    try:
        os.kill(-leader, signal.SIGTERM)
    except OSError:
        pass
    time.sleep(grace)
    if alive(leader):
        try:
            os.kill(-leader, signal.SIGKILL)
        except OSError:
            pass
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
