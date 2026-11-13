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

use Quince\CrossSelling\Recommendation\BoughtTogether;

/**
 * 0.2.0: „kupowane razem" z tabeli par zamiast liczenia z historii zamówień
 * przy każdym wyświetleniu.
 *
 * Tabela powstaje od razu wypełniona z opłaconych zamówień, a hook zmiany
 * statusu zamówienia prowadzi ją dalej.
 *
 * @param Module $module
 */
function upgrade_module_0_2_0($module): bool
{
    if (!BoughtTogether::installTable() || !$module->registerHook('actionOrderStatusPostUpdate')) {
        return false;
    }

    (new BoughtTogether())->rebuild();

    return true;
}
