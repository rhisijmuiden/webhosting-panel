"""whp-agent: the root daemon of Dekwerk.

The panel talks to it over a Unix socket. Only registered actions can run,
and actions never hand their parameters to a shell.
"""

__version__ = "1.0.0"
