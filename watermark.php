<?php
/**
 * 2007-2016 PrestaShop
 *
 * thirty bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2021 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark & property of PrestaShop SA
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class Watermark extends Module
{
    /**
     * Watermark constructor.
     *
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'watermark';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'thirty bees';
        $this->need_instance = false;
        $this->tb_versions_compliancy = '>= 1.0.0';
        $this->tb_min_version = '1.0.0';
		$this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Watermark');
        $this->description = $this->l('Protect image by watermark.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');
    }

    /**
     * Module install functiton
     *
     * @return bool
     * @throws Adapter_Exception
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        $this->writeHtaccessSection();
        if (!parent::install() || !$this->registerHook('watermark')) {
            return false;
        }
        Configuration::updateValue('WATERMARK_TRANSPARENCY', 60);
        Configuration::updateValue('WATERMARK_Y_ALIGN', 'bottom');
        Configuration::updateValue('WATERMARK_X_ALIGN', 'right');
        $this->installFixtures();

        return true;
    }

    /**
     * Module uninstall function
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        $this->removeHtaccessSection();

        return (
            parent::uninstall()
            && Configuration::deleteByName('WATERMARK_TYPES')
            && Configuration::deleteByName('WATERMARK_TRANSPARENCY')
            && Configuration::deleteByName('WATERMARK_Y_ALIGN')
            && Configuration::deleteByName('WATERMARK_LOGGED')
            && Configuration::deleteByName('WATERMARK_X_ALIGN')
        );
    }

    /**
     * Validate form post
     *
     * @return string[]
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function _postValidation()
    {
        $yAlign = Tools::getValue('yalign');
        $xAlign = Tools::getValue('xalign');
        $transparency = (int)(Tools::getValue('transparency'));

        $types = ImageType::getImagesTypes('products');
        $id_image_type = [];
        foreach ($types as $type) {
            if (!is_null(Tools::getValue('WATERMARK_TYPES_' . (int)$type['id_image_type']))) {
                $id_image_type['WATERMARK_TYPES_' . (int)$type['id_image_type']] = true;
            }
        }

        $errors = [];

        if (empty($transparency)) {
            $errors[] = $this->l('Opacity required.');
        } elseif ($transparency < 1 || $transparency > 100) {
            $errors[] = $this->l('Opacity is not in allowed range.');
        }

        if (empty($yAlign)) {
            $errors[] = $this->l('Y-Align is required.');
        } elseif (!in_array($yAlign, ['top', 'middle', 'bottom'])) {
            $errors[] = $this->l('Y-Align is not in allowed range.');
        }

        if (empty($xAlign)) {
            $errors[] = $this->l('X-Align is required.');
        } elseif (!in_array($xAlign, ['left', 'middle', 'right'])) {
            $errors[] = $this->l('X-Align is not in allowed range.');
        }
        if (!count($id_image_type)) {
            $errors[] = $this->l('At least one image type is required.');
        }

        if (isset($_FILES['PS_WATERMARK']['tmp_name']) && !empty($_FILES['PS_WATERMARK']['tmp_name'])) {
            if (!ImageManager::isRealImage($_FILES['PS_WATERMARK']['tmp_name'], $_FILES['PS_WATERMARK']['type'], ['image/gif'])) {
                $errors[] = $this->l('Image must be in GIF format.');
            }
        }

        return $errors;
    }

    /**
     * Post process form submit
     *
     * @return string[]
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function _postProcess()
    {
        $types = ImageType::getImagesTypes('products');
        $id_image_type = [];
        foreach ($types as $type) {
            if (Tools::getValue('WATERMARK_TYPES_' . (int)$type['id_image_type'])) {
                $id_image_type[] = $type['id_image_type'];
            }
        }

        Configuration::updateValue('WATERMARK_TYPES', implode(',', $id_image_type));
        Configuration::updateValue('WATERMARK_Y_ALIGN', Tools::getValue('yalign'));
        Configuration::updateValue('WATERMARK_X_ALIGN', Tools::getValue('xalign'));
        Configuration::updateValue('WATERMARK_TRANSPARENCY', Tools::getValue('transparency'));
        Configuration::updateValue('WATERMARK_LOGGED', Tools::getValue('WATERMARK_LOGGED'));

        $errors = [];
        //submitted watermark
        if (isset($_FILES['PS_WATERMARK']) && !empty($_FILES['PS_WATERMARK']['tmp_name'])) {
            /* Check watermark validity */
            if ($error = ImageManager::validateUpload($_FILES['PS_WATERMARK'])) {
                $errors[] = $error;
            } else {
                /* Copy new watermark */
                $source = $_FILES['PS_WATERMARK']['tmp_name'];
                $target = Shop::getContext() == Shop::CONTEXT_SHOP
                    ? static::getWatermarkImagePath($this->context->shop->id)
                    : static::getWatermarkImagePath();

                if (! @copy($source, $target)) {
                    $errors[] = sprintf($this->l('An error occurred while uploading watermark: %1$s to %2$s'), $source, $target);
                }
            }
        }

        if ($errors) {
            return $errors;
        } else {
            Tools::redirectAdmin('index.php?tab=AdminModules&configure=' . $this->name . '&conf=6&token=' . Tools::getAdminTokenLite('AdminModules'));
            return [];
        }
    }

    /**
     * @return string
     */
    protected function getAdminDir()
    {
        $admin_dir = str_replace('\\', '/', _PS_ADMIN_DIR_);
        $admin_dir = explode('/', $admin_dir);
        $len = count($admin_dir);

        return $len > 1 ? $admin_dir[$len - 1] : _PS_ADMIN_DIR_;
    }

    /**
     * @return bool
     */
    protected function removeHtaccessSection()
    {
        $key1 = "\n# start ~ module watermark section";
        $key2 = "# end ~ module watermark section\n";
        $path = _PS_ROOT_DIR_ . '/.htaccess';
        if (file_exists($path) && is_writable($path)) {
            $s = file_get_contents($path);
            $p1 = strpos($s, $key1);
            $p2 = strpos($s, $key2, $p1);
            if ($p1 === false || $p2 === false) {
                return false;
            }
            $s = substr($s, 0, $p1) . substr($s, $p2 + strlen($key2));
            file_put_contents($path, $s);
        }

        return true;
    }

    /**
     *
     */
    protected function writeHtaccessSection()
    {
        $admin_dir = $this->getAdminDir();
        $source = "\n# start ~ module watermark section
<IfModule mod_rewrite.c>
Options +FollowSymLinks
RewriteEngine On
RewriteCond expr \"! %{HTTP_REFERER} -strmatch '*://%{HTTP_HOST}*/$admin_dir/*'\"
RewriteRule [0-9/]+/[0-9]+\\.jpg$ - [F]
</IfModule>
# end ~ module watermark section\n";

        $path = _PS_ROOT_DIR_ . '/.htaccess';
        file_put_contents($path, $source . file_get_contents($path));
    }

    /**
     * Module configuration page
     *
     * @return string
     * @throws Adapter_Exception
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        //Modify htaccess to prevent downlaod of original pictures
        $this->removeHtaccessSection();
        $this->writeHtaccessSection();

        $html = '';
        if (Tools::isSubmit('btnSubmit')) {
            $errors = $this->_postValidation();
            if (! $errors) {
                $errors = $this->_postProcess();
            }

            if ($errors) {
                foreach ($errors as $err) {
                    $html .= $this->displayError($err);
                }
            }
        }

        $html .= $this->renderForm();

        return $html;
    }


    /**
     * Retro-compatibility hook
     *
     * @param $params
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopException
     */
    public function hookWatermark($params)
    {
        $this->hookActionWatermark($params);
    }

    /**
     * Watermark hook
     *
     * @param $params
     * @return bool
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopException
     */
    public function hookActionWatermark($params)
    {
        $image = new Image($params['id_image']);
        $image->id_product = $params['id_product'];
        $file = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '-watermark.jpg';
        $file_org = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.jpg';

        if (Shop::getContext() != Shop::CONTEXT_SHOP) {
            $watermarkImage = static::getWatermarkImagePath($this->context->shop->id);
            if (! file_exists($watermarkImage)) {
                $watermarkImage = static::getWatermarkImagePath();
            }
        } else {
            $watermarkImage = static::getWatermarkImagePath();
        }

        // if watermark image is not defined, do nothing
        if (! file_exists($watermarkImage)) {
            return false;
        }

        //first make a watermark image
        $return = $this->watermarkByImage(
            _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.jpg',
            $watermarkImage,
            $file
        );

        $imageTypes = $this->getWatermarkImageTypes();
        if (isset($params['image_type']) && is_array($params['image_type'])) {
            $imageTypes = array_intersect($imageTypes, $params['image_type']);
        }

        //go through file formats defined for watermark and resize them
        foreach ($imageTypes as $imageType) {
            $newFile = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '-' . stripslashes($imageType['name']) . '.jpg';
            if (!ImageManager::resize($file, $newFile, (int)$imageType['width'], (int)$imageType['height'])) {
                $return = false;
            }

            $new_file_org = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '-' . stripslashes($imageType['name']) . '-' . $this->getWatermarkHash() . '.jpg';
            if (!ImageManager::resize($file_org, $new_file_org, (int)$imageType['width'], (int)$imageType['height'])) {
                $return = false;
            }
        }

        return $return;
    }

    /**
     * Generate watermark image
     *
     * @param string $imagePath
     * @param string $watermarkPath
     * @param string $outputPath
     * @return bool
     * @throws PrestaShopException
     */
    protected function watermarkByImage($imagePath, $watermarkPath, $outputPath)
    {
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($tmp_width, $tmp_height, $type) = getimagesize($imagePath);
        $image = ImageManager::create($type, $imagePath);

        if (!$image) {
            return false;
        }
        if (!$imagew = imagecreatefromgif($watermarkPath)) {
            throw new PrestaShopException('The watermark image is not a real GIF, please CONVERT the image.');
        }

        list($watermarkWidth, $watermarkHeight) = getimagesize($watermarkPath);
        list($imageWidth, $imageHeight) = getimagesize($imagePath);

        // merge source and watermark image
        if (! imagecopymerge(
            $image,
            $imagew,
            $this->getWatermarkXPosition($imageWidth, $watermarkWidth),
            $this->getWatermarkYPosition($imageHeight, $watermarkHeight),
            0,
            0,
            $watermarkWidth,
            $watermarkHeight,
            $this->getWatermarkTransparency())
        ) {
            return false;
        }

        switch ($type) {
            case IMAGETYPE_PNG:
                $type = 'png';
                break;
            case IMAGETYPE_GIF:
                $type = 'gif';
                break;
            case IMAGETYPE_JPEG:
                $type = 'jpg';
                break;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        return ImageManager::write($type, $image, $outputPath);
    }

    /**
     * Returns configuration form
     *
     * @return string
     * @throws Adapter_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    protected function renderForm()
    {
        $types = ImageType::getImagesTypes('products');
        foreach ($types as $key => $type) {
            $types[$key]['label'] = $type['name'] . ' (' . $type['width'] . ' x ' . $type['height'] . ')';
        }

        if (Shop::getContext() == Shop::CONTEXT_SHOP) {
            $thumb = static::getWatermarkImageUri($this->context->shop->id);
        } else {
            $thumb = static::getWatermarkImageUri();
        }

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ],
                'description' => $this->l('Once you have set up the module, regenerate the images using the "Images" tool in Preferences. However, the watermark will be added automatically to new images.'),
                'input' => [
                    [
                        'type' => 'file',
                        'label' => $this->l('Watermark file:'),
                        'name' => 'PS_WATERMARK',
                        'desc' => $this->l('Must be in GIF format'),
                        'thumb' => $thumb
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Watermark opacity (1-100)'),
                        'name' => 'transparency',
                        'class' => 'fixed-width-md',
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Watermark X align:'),
                        'name' => 'xalign',
                        'class' => 'fixed-width-md',
                        'options' => [
                            'query' => [
                                [
                                    'id' => 'left',
                                    'name' => $this->l('left')
                                ],
                                [
                                    'id' => 'middle',
                                    'name' => $this->l('middle')
                                ],
                                [
                                    'id' => 'right',
                                    'name' => $this->l('right')
                                ]
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Watermark Y align:'),
                        'name' => 'yalign',
                        'class' => 'fixed-width-md',
                        'options' => [
                            'query' => [
                                [
                                    'id' => 'top',
                                    'name' => $this->l('top')
                                ],
                                [
                                    'id' => 'middle',
                                    'name' => $this->l('middle')
                                ],
                                [
                                    'id' => 'bottom',
                                    'name' => $this->l('bottom')
                                ]
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'type' => 'checkbox',
                        'name' => 'WATERMARK_TYPES',
                        'label' => $this->l('Choose image types for watermark protection:'),
                        'values' => [
                            'query' => $types,
                            'id' => 'id_image_type',
                            'name' => 'label'
                        ]
                    ],
                    [
                        'type' => "switch",
                        'name' => 'WATERMARK_LOGGED',
                        'label' => $this->l('Logged in customers see images without watermark'),
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                ]
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Returns configuration form values
     *
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function getConfigFieldsValues()
    {
        $config_fields = [
            'PS_WATERMARK' => '',
            'transparency' => Tools::getValue('transparency', $this->getWatermarkTransparency()),
            'xalign' => Tools::getValue('xalign', Configuration::get('WATERMARK_X_ALIGN')),
            'yalign' => Tools::getValue('yalign', Configuration::get('WATERMARK_Y_ALIGN')),
            'WATERMARK_LOGGED' => Tools::getValue('WATERMARK_LOGGED', Configuration::get('WATERMARK_LOGGED')),
        ];
        //get all images type available
        $types = ImageType::getImagesTypes('products');
        $id_image_type = [];
        foreach ($types as $type) {
            $id_image_type[] = $type['id_image_type'];
        }

        //get images type from $_POST
        $id_image_type_post = [];
        foreach ($id_image_type as $id) {
            if (Tools::getValue('WATERMARK_TYPES_' . (int)$id)) {
                $id_image_type_post['WATERMARK_TYPES_' . (int)$id] = true;
            }
        }

        //get images type from Configuration
        $id_image_type_config = [];
        if ($confs = Configuration::get('WATERMARK_TYPES')) {
            $confs = explode(',', Configuration::get('WATERMARK_TYPES'));
        } else {
            $confs = [];
        }

        foreach ($confs as $conf) {
            $id_image_type_config['WATERMARK_TYPES_' . (int)$conf] = true;
        }

        //return only common values and value from post
        if (Tools::isSubmit('btnSubmit')) {
            $config_fields = array_merge($config_fields, array_intersect($id_image_type_post, $id_image_type_config));
        } else {
            $config_fields = array_merge($config_fields, $id_image_type_config);
        }

        return $config_fields;
    }

    /**
     * Returns watermark transparency value
     *
     * @return int
     * @throws PrestaShopException
     */
    protected function getWatermarkTransparency()
    {
        $transparency = (int)Configuration::get('WATERMARK_TRANSPARENCY');
        if ($transparency < 1 || $transparency > 100) {
            return 60;
        }
        return $transparency;
    }

    /**
     * Returns image types selected for watermark
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function getWatermarkImageTypes()
    {
        static $imageTypes = null;
        if (is_null($imageTypes)) {
            $types = Configuration::get('WATERMARK_TYPES');
            if ($types) {
                $types = explode(',', $types);
            } else {
                $types = [];
            }
            $imageTypes = [];
            foreach (ImageType::getImagesTypes('products') as $type) {
                if (in_array($type['id_image_type'], $types)) {
                    $imageTypes[] = $type;
                }
            }
        }
        return $imageTypes;
    }

    /**
     * Returns watermark secret hash, used for direct image access
     *
     * @return string
     * @throws HTMLPurifier_Exception
     * @throws PrestaShopException
     */
    protected function getWatermarkHash()
    {
        $hash = Configuration::get('WATERMARK_HASH');
        if (! $hash) {
            $hash = Tools::passwdGen(10);
            Configuration::updateValue('WATERMARK_HASH', $hash);
        }
        return $hash;
    }

    /**
     * Returns x position of watermark
     *
     * @param int $imageWidth
     * @param int $watermarkWidth
     * @return float | int
     * @throws PrestaShopException
     */
    protected function getWatermarkXPosition($imageWidth, $watermarkWidth)
    {
        $xAlign = Configuration::get('WATERMARK_X_ALIGN');
        switch ($xAlign) {
            case 'middle':
                return $imageWidth / 2 - $watermarkWidth / 2;
            case 'left':
                return 0;
            case 'right':
            default:
                return $imageWidth - $watermarkWidth;
        }
    }

    /**
     * Returns y position of watermark
     *
     * @param int $imageHeight
     * @param int $watermarkHeight
     * @return float|int
     * @throws PrestaShopException
     */
    protected function getWatermarkYPosition($imageHeight, $watermarkHeight)
    {
        $yAlign = Configuration::get('WATERMARK_Y_ALIGN');
        switch ($yAlign) {
            case 'middle':
                return $imageHeight / 2 - $watermarkHeight / 2;
            case 'top':
                return 0;
            case 'bottom':
            default:
                return $imageHeight - $watermarkHeight;
        }
    }

    /**
     * Installs watermark fixture to destination directory
     */
    protected function installFixtures()
    {
        $source = static::normalizePath(__DIR__) . 'fixtures/watermark.gif';
        $target = static::getWatermarkImagePath();
        if (! file_exists($target) && file_exists($source)) {
            @copy($source, $target);
        }
    }

    /**
     * Returns image path for watermark image, in given shop context
     *
     * @param int $idShop
     * @return string
     */
    protected static function getWatermarkImagePath($idShop = null)
    {
        $idShop = (int)$idShop;
        if ($idShop) {
            $shopSuffix = '-' . $idShop;
        } else {
            $shopSuffix = '';
        }
        return static::normalizePath(_PS_IMG_DIR_) . 'watermark' . $shopSuffix . '.gif';
    }

    /**
     * Returns image uri for watermark image, in given shop context
     *
     * @param int $idShop
     * @return string
     */
    protected static function getWatermarkImageUri($idShop = null)
    {
        // first look if there is specific image for given shop
        $idShop = (int)$idShop;
        if ($idShop && file_exists(static::getWatermarkImagePath($idShop))) {
            return _PS_IMG_ . 'watermark-' . $idShop . '.gif';
        }
        // if not, return image for all shops
        if (file_exists(static::getWatermarkImagePath())) {
            return _PS_IMG_ . 'watermark.gif';
        }
        // nothing found
        return null;
    }

    /**
     * Returns normalized path
     *
     * @param string $dir
     * @return string
     */
    public static function normalizePath($dir)
    {
       return rtrim(str_replace('\\', '/', $dir), '/') . '/';
    }
}
