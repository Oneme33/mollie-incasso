#!/bin/sh
# Run one test suite; it only passes when it reached its final RESULT line.
# A wp_die() or exit inside WordPress can end a script early with exit code 0.
out=$(php "$@" 2>&1)
code=$?
printf '%s\n' "$out"
if [ "$code" -ne 0 ] || ! printf '%s\n' "$out" | grep -q '^RESULT '; then
  echo "Suite did not finish: exit $code, no RESULT line." >&2
  exit 1
fi
