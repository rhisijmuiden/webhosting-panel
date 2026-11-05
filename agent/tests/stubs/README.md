Minimal stand-ins for the phase 2 agent core (registry, validate, run, jobs),
so the phase 3 actions can be unit tested before and independently of it.
Tests put this folder after the real package on sys.path, so the real modules
win when they exist.
