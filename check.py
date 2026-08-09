#!/usr/bin/env python3
"""Checks that php -l cannot make.

A missing translation key does not error: Blade prints the raw key, so the page
still renders and the mistake ships. Same for a Livewire action wired to a method
that does not exist, which fails only when somebody clicks it. Both are cheap to
find by reading the files and expensive to find in production.
"""
import re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
FAILED = []


def check(ok, label, detail=""):
    print("  %-4s %s%s" % ("PASS" if ok else "FAIL", label, "" if ok else "  <- " + detail))
    if not ok:
        FAILED.append(label)


def lang_keys(path):
    """Flatten a Laravel messages file into dotted keys, by indentation."""
    keys, stack = set(), []
    for line in path.read_text().splitlines():
        m = re.match(r"^(\s+)'([^']+)' => (\[)?", line)
        if not m:
            continue
        depth = len(m.group(1)) // 4
        stack = stack[: depth - 1] + [m.group(2)]
        if not m.group(3):
            keys.add(".".join(stack))
    return keys


def main():
    en = lang_keys(ROOT / "lang/en/messages.php")
    nl = lang_keys(ROOT / "lang/nl/messages.php")
    check(en == nl, "en en nl hebben dezelfde sleutels (%d)" % len(en),
          "alleen en: %s / alleen nl: %s" % (sorted(en - nl)[:4], sorted(nl - en)[:4]))

    blade = (ROOT / "resources/views/manage-mods.blade.php").read_text()
    page = (ROOT / "src/Filament/Server/Pages/ManageMods.php").read_text()

    # Literal keys only. Anything built by concatenation is checked by prefix
    # below, because the suffix is not knowable from the source.
    used = set(re.findall(r"trans(?:_choice)?\('pzmm::messages\.([a-z_.]+)'\)", blade + page))
    used |= set(re.findall(r"trans(?:_choice)?\('pzmm::messages\.([a-z_.]+)',", blade + page))
    missing = sorted(k for k in used if k not in en and not k.endswith("."))
    check(not missing, "elke vaste vertaalsleutel bestaat (%d)" % len(used), ", ".join(missing[:6]))

    # Dynamic keys: 'pzmm::messages.auto.phase.' . $phase
    prefixes = set(re.findall(r"'pzmm::messages\.([a-z_.]+\.)'\s*\.", blade + page))
    for prefix in sorted(prefixes):
        check(any(k.startswith(prefix) for k in en),
              "prefix %s heeft vertalingen" % prefix)

    # Every wire:click target must be a public method on the page.
    methods = set(re.findall(r"public function (\w+)\(", page))
    called = set(re.findall(r'wire:(?:click|poll\.\d+s)="(\w+)', blade))
    called |= set(re.findall(r"'method' => '(\w+)'", page))
    ghosts = sorted(called - methods)
    check(not ghosts, "elke wire:click bestaat als methode (%d)" % len(called), ", ".join(ghosts))

    # Livewire binds these; a typo silently binds to nothing.
    props = set(re.findall(r"public (?:\w+ )?\$(\w+)", page))
    bound = set(re.findall(r'wire:model(?:\.[\w.]+)?="(\w+)', blade))
    unbound = sorted(bound - props)
    check(not unbound, "elke wire:model bestaat als property (%d)" % len(bound), ", ".join(unbound))

    # The settings form and the store must agree on field names, or a saved
    # value lands in a key nothing reads.
    store = (ROOT / "src/Services/StateStore.php").read_text()
    defaults = set(re.findall(r"^\s+'(\w+)' => ", store[store.index("AUTO_DEFAULTS"):store.index("AUTO_MIN")], re.M))
    form = set(re.findall(r'wire:model="auto\.(\w+)"', blade))
    form |= set(re.findall(r"'k' => '(\w+)'", blade))
    strays = sorted(f for f in form if f not in defaults and f not in ("active", "available", "restart", "errors"))
    check(not strays, "elk instelveld bestaat in AUTO_DEFAULTS (%d)" % len(form), ", ".join(strays))

    print()
    if FAILED:
        sys.exit("RESULT: %d controle(s) gefaald" % len(FAILED))
    print("RESULT: alles ok")


if __name__ == "__main__":
    main()
