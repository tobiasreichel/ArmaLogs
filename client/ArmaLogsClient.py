"""Entry point for the frozen Windows executable.

PyInstaller-friendly top-level script that imports the client package absolutely.
"""
import sys
from pathlib import Path

if getattr(sys, "frozen", False):
    bundle_dir = Path(sys.executable).parent
else:
    bundle_dir = Path(__file__).parent

# Ensure the parent directory (which contains the 'client' package) is on sys.path
sys.path.insert(0, str(bundle_dir))

from client.main import main


__version__ = "1.1.9"


if __name__ == "__main__":
    main()
