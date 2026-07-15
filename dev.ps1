# Lil' Budgie dev shortcuts for Windows PowerShell.
#
#   .\dev.ps1 up         # docker + API + Reverb + worker + web (each in own window)
#   .\dev.ps1 backend    # docker + API + Reverb + worker (own windows), no web
#   .\dev.ps1 phone      # adb tunnel + build & run on the connected device
#   .\dev.ps1 <task>     # see the switch below for all tasks
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'backend', 'docker', 'api', 'reverb', 'worker', 'scheduler', 'web', 'tunnel', 'phone', 'test', 'help')]
    [string]$Task = 'help'
)

$root = $PSScriptRoot

function Start-InNewWindow([string]$title, [string]$command) {
    Start-Process powershell -ArgumentList '-NoExit', '-Command',
        "`$Host.UI.RawUI.WindowTitle = '$title'; $command"
}

function Start-Backend {
    docker compose up -d 2>$null
    Start-InNewWindow 'budgie-api'    "Set-Location '$root\api'; php artisan serve --host=0.0.0.0 --port=8000"
    Start-InNewWindow 'budgie-reverb' "Set-Location '$root\api'; php artisan reverb:start --host=0.0.0.0 --port=8080"
    Start-InNewWindow 'budgie-worker' "Set-Location '$root\api'; php artisan queue:work --tries=3"
}

function Invoke-Tunnel {
    adb reverse tcp:8000 tcp:8000
    Write-Host 'USB tunnel up: phone localhost:8000 -> this machine :8000'
}

switch ($Task) {
    'docker' {
        docker compose up -d
    }
    'api' {
        Set-Location "$root\api"; php artisan serve --host=0.0.0.0 --port=8000
    }
    'reverb' {
        Set-Location "$root\api"; php artisan reverb:start --host=0.0.0.0 --port=8080
    }
    'worker' {
        Set-Location "$root\api"; php artisan queue:work --tries=3
    }
    'scheduler' {
        Set-Location "$root\api"; php artisan schedule:work
    }
    'web' {
        Set-Location "$root\web"; npm run dev
    }
    'tunnel' {
        Invoke-Tunnel
    }
    'phone' {
        Invoke-Tunnel
        Set-Location "$root\mobile"
        flutter run
    }
    'backend' {
        Start-Backend
        Write-Host 'API, Reverb and queue worker started in their own windows.'
    }
    'up' {
        Start-Backend
        Start-InNewWindow 'budgie-web' "Set-Location '$root\web'; npm run dev"
        Write-Host 'Full stack started. Web: http://localhost:3000  API: http://localhost:8000  Mail: http://localhost:8025'
    }
    'test' {
        Set-Location "$root\api"; php artisan test
        Set-Location "$root\mobile"; flutter analyze; flutter test
    }
    default {
        Write-Host @'
Lil' Budgie dev tasks:
  .\dev.ps1 up         docker + API + Reverb + worker + web (each in own window)
  .\dev.ps1 backend    docker + API + Reverb + worker (own windows), no web
  .\dev.ps1 docker     start MariaDB/Redis/Mailpit containers
  .\dev.ps1 api        run the Laravel API on 0.0.0.0:8000
  .\dev.ps1 reverb     run the Reverb websocket server on :8080
  .\dev.ps1 worker     run the queue worker (emails, invitations)
  .\dev.ps1 scheduler  run the scheduler (posts due scheduled transactions)
  .\dev.ps1 web        run the Nuxt web app on :3000
  .\dev.ps1 tunnel     adb reverse: phone localhost:8000 -> this machine
  .\dev.ps1 phone      tunnel + build & run the app on the connected device
  .\dev.ps1 test       run API + mobile test suites
'@
    }
}
