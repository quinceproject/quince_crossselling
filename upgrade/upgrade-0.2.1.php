<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.2.1: czwarte miejsce: krótka lista „Nie zapomnij" na stronie koszyka,
 * obok karuzeli pod spodem. Osobne ustawienie (`PLACEMENT_CART` ma już
 * karuzelę; ta lista dostaje własny hook, bo stoi w innym miejscu strony).
 *
 * @param Module $module
 */
function upgrade_module_0_2_1($module): bool
{
    if (!Hook::getIdByName(Quince_Crossselling::HOOK_CART_SUGGEST)) {
        $hook = new Hook();
        $hook->name = Quince_Crossselling::HOOK_CART_SUGGEST;
        $hook->title = 'Quince: dokupka na stronie koszyka';
        $hook->description = 'Krótka lista „Nie zapomnij" pod pozycjami na stronie koszyka.';
        $hook->position = false;

        if (!$hook->add()) {
            return false;
        }
    }

    return $module->registerHook(Quince_Crossselling::HOOK_CART_SUGGEST);
}
