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
 * 0.3.1: pola list w formularzu produktu jako wyszukiwarka produktów, tak jak
 * „Produkty powiązane". Definicje pól dostają własny typ formularza; w bazie
 * i w API listy zostają tekstem z numerami SKU, wpisane listy się nie zmieniają.
 *
 * @param Quince_Crossselling $module
 */
function upgrade_module_0_3_1($module): bool
{
    return $module->registerHook('actionAdminControllerSetMedia')
        && $module->installListFields();
}
