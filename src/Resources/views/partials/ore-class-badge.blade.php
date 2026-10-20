{{-- A kind of ore as the tax settings name it, moon ore in its rarity's colour.
     $oreClass is an OreClassifier category: moon_r4 to moon_r64, moon, ore, ice,
     gas, abyssal or triglavian. --}}
@php
    $oreRarity = str_starts_with($oreClass, 'moon_') ? substr($oreClass, 5) : '';
    [$oreBadgeClass, $oreBadgeLabel] = match (true) {
        str_starts_with($oreClass, 'moon') => [
            $oreRarity !== '' ? \MiningManager\Services\Moon\MoonOreHelper::rarityBadgeClass($oreRarity) : 'badge-warning',
            trim(trans('mining-manager::ledger.moon_ore') . ' ' . strtoupper($oreRarity)),
        ],
        $oreClass === 'ice' => ['badge-info', trans('mining-manager::ledger.ice')],
        $oreClass === 'gas' => ['badge-success', trans('mining-manager::ledger.gas')],
        $oreClass === 'abyssal' => ['badge-danger', trans('mining-manager::ledger.abyssal')],
        $oreClass === 'triglavian' => ['badge-dark', trans('mining-manager::ledger.triglavian')],
        default => ['badge-secondary', trans('mining-manager::ledger.regular_ore')],
    };
@endphp
<span class="badge {{ $oreBadgeClass }}">{{ $oreBadgeLabel }}</span>
