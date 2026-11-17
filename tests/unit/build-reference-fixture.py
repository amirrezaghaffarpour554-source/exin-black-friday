"""Regenerate independent exact Python integer arithmetic pricing fixtures.

No PHP code is reused; Python's arbitrary precision integers act as an oracle.
"""

from pathlib import Path
import json
import random


random_source = random.Random(830)
vectors = []
for index in range(24):
    base = random_source.randrange(1_000_000_000_000, 2**63)
    floor = random_source.randrange(1, base + 1)
    days = random_source.randrange(2, 367)
    power = random_source.randrange(1, 9)
    denominator = (days - 1) ** power
    for rounding in ("half_up", "floor", "ceil"):
        expected = {}
        for day in sorted({0, 1, days // 3, days // 2, days - 2, days - 1}):
            numerator = base * denominator - (base - floor) * day**power
            price, remainder = divmod(numerator, denominator)
            if (rounding == "half_up" and 2 * remainder >= denominator) or (
                rounding == "ceil" and remainder
            ):
                price += 1
            expected[str(day)] = f"{price // 100}.{price % 100:02}"
        vectors.append(
            {
                "base": f"{base // 100}.{base % 100:02}",
                "floor": f"{floor // 100}.{floor % 100:02}",
                "days": days,
                "power": power,
                "rounding": rounding,
                "expected": expected,
            }
        )

Path(__file__).with_name("reference-vectors.json").write_text(
    json.dumps(vectors, indent=2) + "\n", encoding="utf-8"
)
