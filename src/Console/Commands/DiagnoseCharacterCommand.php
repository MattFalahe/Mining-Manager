<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Character\CharacterInfoService;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Illuminate\Support\Facades\DB;

class DiagnoseCharacterCommand extends Command
{
    protected $signature = 'mining-manager:diagnose-character {character_id}';
    protected $description = 'Diagnose character corporation lookup issues';

    public function handle()
    {
        $characterId = $this->argument('character_id');
        
        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║   Mining Manager - Character Diagnostic                    ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
        $this->line('');
        $this->info("Diagnosing character ID: {$characterId}");
        $this->line('');
        
        // Check character_infos
        $this->line("1️⃣  Checking character_infos table...");
        $character = CharacterInfo::find($characterId);
        
        if ($character) {
            $this->info("   ✅ Character found in character_infos");
            $this->line("   • Name: {$character->name}");
            $this->line("   • Corporation ID: " . ($character->corporation_id ?? 'NULL'));
        } else {
            $this->warn("   ⚠️  Character NOT found in character_infos");
            $this->warn("   This character is not registered in SeAT");
            return;
        }
        
        $this->line('');
        
        // Check character_affiliations
        $this->line("2️⃣  Checking character_affiliations table...");
        $affiliation = CharacterAffiliation::where('character_id', $characterId)->first();
        
        if ($affiliation) {
            $this->info("   ✅ Affiliation found");
            $this->line("   • Corporation ID: " . ($affiliation->corporation_id ?? 'NULL'));
            $this->line("   • Corporation Name: " . ($affiliation->corporation_name ?? 'NULL'));
            $this->line("   • Alliance ID: " . ($affiliation->alliance_id ?? 'NULL'));
        } else {
            $this->warn("   ⚠️  No affiliation record found");
        }
        
        $this->line('');
        
        // Check corporation_infos
        if ($character->corporation_id || ($affiliation && $affiliation->corporation_id)) {
            $corpId = ($affiliation && $affiliation->corporation_id) ? $affiliation->corporation_id : $character->corporation_id;
            
            $this->line("3️⃣  Checking corporation_infos table for corp ID {$corpId}...");
            $corporation = CorporationInfo::find($corpId);
            
            if ($corporation) {
                $this->info("   ✅ Corporation found in corporation_infos");
                $this->line("   • Corporation Name: {$corporation->name}");
                $this->line("   • Ticker: {$corporation->ticker}");
                $this->line("   • Member Count: {$corporation->member_count}");
            } else {
                $this->warn("   ⚠️  Corporation NOT found in corporation_infos");
                
                // Try direct query
                $corpName = DB::table('corporation_infos')
                    ->where('corporation_id', $corpId)
                    ->value('name');
                
                if ($corpName) {
                    $this->info("   ✅ Found via direct query: {$corpName}");
                } else {
                    $this->error("   ❌ Corporation data not in SeAT database");
                    $this->line("   • Corporation ID {$corpId} needs to be fetched from ESI");
                }
            }
        } else {
            $this->warn("3️⃣  No corporation_id to lookup");
        }
        
        $this->line('');
        
        // Check refresh_tokens (user relationship)
        $this->line("4️⃣  Checking user relationship (refresh_tokens)...");
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->value('user_id');
        
        if ($userId) {
            $this->info("   ✅ Character linked to user ID: {$userId}");
            
            $mainCharId = DB::table('users')
                ->where('id', $userId)
                ->value('main_character_id');
            
            if ($mainCharId) {
                $this->line("   • Main character ID: {$mainCharId}");
                
                if ($mainCharId != $characterId) {
                    $this->warn("   ⚠️  This is an ALT character");
                    $mainChar = CharacterInfo::find($mainCharId);
                    if ($mainChar) {
                        $this->line("   • Main character name: {$mainChar->name}");
                    }
                } else {
                    $this->info("   ✓ This is a MAIN character");
                }
            } else {
                $this->warn("   ⚠️  User has no main_character_id set");
            }
        } else {
            $this->warn("   ⚠️  Character not linked to any user");
        }
        
        $this->line('');
        
        // Test external API if corporation not found in SeAT
        if ($affiliation && $affiliation->corporation_id) {
            $corpId = $affiliation->corporation_id;
            
            if (!$corporation) {
                $this->line("🌐 Testing External API (ESI)...");
                
                try {
                    $externalService = app(\MiningManager\Services\Character\ExternalCharacterService::class);
                    $corpName = $externalService->getCorporationName($corpId);
                    
                    if ($corpName) {
                        $this->info("   ✅ Corporation found via ESI API: {$corpName}");
                        $this->line("   • This will be used as fallback");
                        $this->line("   • Response is cached for 24 hours");
                    } else {
                        $this->warn("   ⚠️  External API returned no name");
                    }
                } catch (\Exception $e) {
                    $this->error("   ❌ External API failed: " . $e->getMessage());
                }
            }
        }
        
        $this->line('');
        
        // Test CharacterInfoService
        $this->line("5️⃣  Testing CharacterInfoService...");
        $service = app(CharacterInfoService::class);
        $info = $service->getCharacterInfo($characterId);
        
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $info['name']],
                ['Corporation ID', $info['corporation_id'] ?? 'NULL'],
                ['Corporation Name', $info['corporation_name']],
                ['Is Registered', $info['is_registered'] ? 'Yes' : 'No'],
                ['Main Character ID', $info['main_character_id'] ?? 'NULL'],
            ]
        );
        
        $this->line('');
        
        // Recommendations
        $this->info("📋 RECOMMENDATIONS:");
        
        if (!$affiliation) {
            $this->warn("• Run: php artisan esi:update:affiliations");
            $this->line("  This will update character affiliations from ESI");
        }
        
        if ($affiliation && $affiliation->corporation_id && !$corporation) {
            $this->warn("• Run: php artisan esi:update:corporations");
            $this->line("  This will fetch corporation {$affiliation->corporation_id} from ESI");
            $this->line("  OR the corporation data will be fetched via external API (cached)");
        }
        
        if ($character->corporation_id && !$affiliation) {
            $this->warn("• Character has corporation_id but no affiliation record");
            $this->line("  Run: php artisan esi:update:affiliations");
        }
        
        if (!$character->corporation_id && $affiliation && $affiliation->corporation_id) {
            $this->info("• character_infos.corporation_id is NULL but affiliation has it");
            $this->line("  The plugin will use affiliation.corporation_id ({$affiliation->corporation_id})");
            $this->line("  This is normal and the corporation name will be looked up");
        }
        
        if ($info['corporation_name'] === 'Unknown Corporation') {
            $this->error("• Corporation name could not be determined");
            $this->line("  1. Run: php artisan esi:update:corporations");
            $this->line("  2. If that doesn't work, external API will be used automatically");
            $corpId = ($affiliation && $affiliation->corporation_id) ? $affiliation->corporation_id : 'N/A';
            $this->line("  3. Check if corporation {$corpId} exists in game");
        }
        
        $this->line('');
        $this->info("✅ Diagnostic complete!");
        
        return Command::SUCCESS;
    }
}
