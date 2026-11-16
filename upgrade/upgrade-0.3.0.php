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
 * 0.3.0: części zamienne i elementy zestawu.
 *
 * Dwa pola produktu (lista numerów artykułów) i hook karuzel tych list na karcie
 * produktu. Nowe źródła podpowiedzi są w ustawieniach domyślnie odznaczone, więc
 * po aktualizacji podpowiedzi w sklepie wyglądają tak samo jak przed nią.
 *
 * @param Quince_Crossselling $module
 */
function upgrade_module_0_3_0($module): bool
{
    if (!Hook::getIdByName(Quince_Crossselling::HOOK_LISTS)) {
        $hook = new Hook();
        $hook->name = Quince_Crossselling::HOOK_LISTS;
        $hook->title = 'Quince:listy produktu na karcie produktu';
        $hook->description = 'Części zamienne, pasuje do, elementy zestawu, w zestawach. Motyw podaje qLista, qIle, qStrzalki i qTytul z panelu strony produktu.';
        $hook->position = false;

        if (!$hook->add()) {
            return false;
        }
    }

    return $module->registerHook(Quince_Crossselling::HOOK_LISTS)
        && $module->installListFields();
}
