@echo off
rem Start the Localia agent page on http://localhost:8787
cd /d "%~dp0"
where py >nul 2>nul && (py -3 serve.py %* & goto :eof)
where python >nul 2>nul && (python serve.py %* & goto :eof)
where node >nul 2>nul && (node serve.js %* & goto :eof)
echo Please install Python 3 (python.org) or Node.js (nodejs.org), or open index.html directly - see README.
pause
