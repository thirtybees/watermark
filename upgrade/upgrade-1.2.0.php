<?php
/**
 * Copyright (C) 2021-2021 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2019 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Upgrade function from 1.1.0 to 1.2.0
 *
 * @param Watermark $module
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    // move all watermarks gif files from module directory to /img/ directory
    $directory = __DIR__ . '/../';
    foreach (scandir($directory) as $item) {
        $source = $directory . $item;
        if (is_file($source) && preg_match('/^watermark(-[0-9]+)*.gif$/', $item)) {
            $target = _PS_IMG_DIR_ . $item;
            @copy($source, $target);
        }
    }
    return true;
}
