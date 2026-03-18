# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT 5.x. Track mining operations, manage moon extractions, calculate taxes, and generate detailed reports for your corporation.

## Features

- **Mining Ledger** — Automated processing of character and corporation mining data
- **Moon Mining** — Extraction tracking, ore composition, jackpot detection, ready-to-fracture alerts
- **Tax System** — Daily summary-based tax calculation, per-ore rates, guest mining support, wallet payment verification
- **Mining Events** — Create events, track participants, leaderboards, bonus percentages
- **Reports** — Daily/weekly/monthly reports with PDF/CSV/JSON export and Discord webhook notifications
- **Theft Detection** — Detect unauthorized mining at corporation moons
- **Dashboard** — Corporation-wide analytics with charts and statistics

## Requirements

- SeAT 5.x
- PHP 8.1+
- MariaDB / MySQL

## Installation

```bash
composer require mattfalahe/mining-manager
php artisan migrate
php artisan db:seed --class=MiningManager\\Database\\Seeders\\ScheduleSeeder
```

After installation, access Mining Manager from the SeAT sidebar. Configure your corporation in **Settings > General** and tax rates in **Settings > Tax Rates**.

## Permissions

| Permission | Description |
|---|---|
| `mining-manager.view` | View mining data and reports |
| `mining-manager.manage_events` | Create and manage mining events |
| `mining-manager.manage_taxes` | Calculate and manage taxes |
| `mining-manager.admin` | Full administrative access |

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Wiki**: [Documentation & Screenshots](https://github.com/MattFalahe/Mining-Manager/wiki)

## License

GNU General Public License v2.0 — see [LICENSE](LICENSE) for details.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide.*
