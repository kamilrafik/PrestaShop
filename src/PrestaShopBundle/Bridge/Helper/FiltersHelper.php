<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace PrestaShopBundle\Bridge\Helper;

use Context;
use ObjectModel;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Bridge\AdminController\FilterPrefix;
use Symfony\Component\HttpFoundation\Request;
use Tools;
use Validate;

/**
 * This class processes filters, stores them in cookies and updates the list's SQL query.
 */
class FiltersHelper
{
    /**
     * @var Context
     */
    private $context;

    /**
     * @var HookDispatcherInterface
     */
    private $hookDispatcher;

    /**
     * @param LegacyContext $legacyContext
     * @param HookDispatcherInterface $hookDispatcher
     */
    public function __construct(LegacyContext $legacyContext, HookDispatcherInterface $hookDispatcher)
    {
        $this->context = $legacyContext->getContext();
        $this->hookDispatcher = $hookDispatcher;
    }

    /**
     * @param Request $request
     * @param HelperListConfiguration $helperListConfiguration
     *
     * @return void
     */
    public function processFilter(
        Request $request,
        HelperListConfiguration $helperListConfiguration
    ): void {
        $this->hookDispatcher->dispatchWithParameters('action' . $helperListConfiguration->legacyControllerName . 'ListingFieldsModifier', [
            'fields' => &$helperListConfiguration->fieldsList,
        ]);

        $prefix = FilterPrefix::getByClassName($helperListConfiguration->legacyControllerName);

        if (!empty($helperListConfiguration->listId)) {
            foreach ($request->request->all() as $key => $value) {
                if ($value === '') {
                    unset($this->context->cookie->{$prefix . $key});
                } elseif (stripos($key, $helperListConfiguration->listId . 'Filter_') === 0) {
                    $this->context->cookie->{$prefix . $key} = !is_array($value) ? $value : json_encode($value);
                } elseif (stripos($key, 'submitFilter') === 0) {
                    $this->context->cookie->$key = !is_array($value) ? $value : json_encode($value);
                }
            }

            foreach ($request->query->all() as $key => $value) {
                if (stripos($key, $helperListConfiguration->listId . 'Filter_') === 0) {
                    $this->context->cookie->{$prefix . $key} = !is_array($value) ? $value : json_encode($value);
                } elseif (stripos($key, 'submitFilter') === 0) {
                    $this->context->cookie->$key = !is_array($value) ? $value : json_encode($value);
                }
                if (stripos($key, $helperListConfiguration->listId . 'Orderby') === 0 && Validate::isOrderBy($value)) {
                    if ($value === '' || $value == $helperListConfiguration->defaultOrderBy) {
                        unset($this->context->cookie->{$prefix . $key});
                    } else {
                        $this->context->cookie->{$prefix . $key} = $value;
                    }
                } elseif (stripos($key, $helperListConfiguration->listId . 'Orderway') === 0 && Validate::isOrderWay($value)) {
                    if ($value === '' || $value == $helperListConfiguration->defaultOrderWay) {
                        unset($this->context->cookie->{$prefix . $key});
                    } else {
                        $this->context->cookie->{$prefix . $key} = $value;
                    }
                }
            }
        }

        $filters = $this->context->cookie->getFamily($prefix . $helperListConfiguration->listId . 'Filter_');
        $definition = false;
        if (isset($this->className) && $this->className) {
            $definition = ObjectModel::getDefinition($this->className);
        }

        foreach ($filters as $key => $value) {
            /* Extracting filters from $_POST on key filter_ */
            if ($value != null && !strncmp($key, $prefix . $helperListConfiguration->listId . 'Filter_', 7 + Tools::strlen($prefix . $helperListConfiguration->listId))) {
                $key = Tools::substr($key, 7 + Tools::strlen($prefix . $helperListConfiguration->listId));
                /* Table alias could be specified using a ! eg. alias!field */
                $tmp_tab = explode('!', $key);
                $filter = count($tmp_tab) > 1 ? $tmp_tab[1] : $tmp_tab[0];

                if ($field = $this->filterToField($helperListConfiguration, $key, $filter)) {
                    $type = (array_key_exists('filter_type', $field) ? $field['filter_type'] : (array_key_exists('type', $field) ? $field['type'] : false));
                    if (($type == 'date' || $type == 'datetime') && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $key = isset($tmp_tab[1]) ? $tmp_tab[0] . '.`' . $tmp_tab[1] . '`' : '`' . $tmp_tab[0] . '`';

                    // Assignment by reference
                    if (array_key_exists('havingFilter', $field)) {
                        $sql_filter = $helperListConfiguration->filterHaving;
                    } else {
                        $sql_filter = &$helperListConfiguration->filter;
                    }

                    /* Only for date filtering (from, to) */
                    if (is_array($value)) {
                        if (isset($value[0]) && !empty($value[0])) {
                            if (Validate::isDate($value[0])) {
                                $sql_filter .= ' AND ' . pSQL($key) . ' >= \'' . pSQL(Tools::dateFrom($value[0])) . '\'';
                            }
                        }

                        if (isset($value[1]) && !empty($value[1])) {
                            if (Validate::isDate($value[1])) {
                                $sql_filter .= ' AND ' . pSQL($key) . ' <= \'' . pSQL(Tools::dateTo($value[1])) . '\'';
                            }
                        }
                    } else {
                        $sql_filter .= ' AND ';
                        $check_key = ($key == $helperListConfiguration->identifier || $key == '`' . $helperListConfiguration->identifier . '`');
                        $alias = ($definition && !empty($definition['fields'][$filter]['shop'])) ? 'sa' : 'a';

                        if ($type == 'int' || $type == 'bool') {
                            $sql_filter .= (($check_key || $key == '`active`') ? $alias . '.' : '') . pSQL($key) . ' = ' . (int) $value . ' ';
                        } elseif ($type == 'decimal') {
                            $sql_filter .= ($check_key ? $alias . '.' : '') . pSQL($key) . ' = ' . (float) $value . ' ';
                        } elseif ($type == 'select') {
                            $sql_filter .= ($check_key ? $alias . '.' : '') . pSQL($key) . ' = \'' . pSQL($value) . '\' ';
                        } elseif ($type == 'price') {
                            $value = (float) str_replace(',', '.', $value);
                            $sql_filter .= ($check_key ? $alias . '.' : '') . pSQL($key) . ' = ' . $value . ' ';
                        } else {
                            $sql_filter .= ($check_key ? $alias . '.' : '') . pSQL($key) . ' LIKE \'%' . pSQL(trim($value)) . '%\' ';
                        }
                    }
                }
            }
        }
    }

    /**
     * @param HelperListConfiguration $helperListConfiguration
     * @param string $key
     * @param string $filter
     *
     * @return false|mixed
     */
    private function filterToField(HelperListConfiguration $helperListConfiguration, $key, $filter)
    {
        if (empty($helperListConfiguration->fieldsList)) {
            return false;
        }

        foreach ($helperListConfiguration->fieldsList as $field) {
            if (array_key_exists('filter_key', $field) && $field['filter_key'] == $key) {
                return $field;
            }
        }
        if (array_key_exists($filter, $helperListConfiguration->fieldsList)) {
            return $helperListConfiguration->fieldsList[$filter];
        }

        return false;
    }
}
