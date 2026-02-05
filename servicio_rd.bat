@echo off
:loop
C:\xampp\php\php.exe C:\xampp\htdocs\rd\procesar_ordenes.php
timeout /t 10 /nobreak >nul
goto loop