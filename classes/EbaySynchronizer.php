<?php

/*
 * 2007-2013 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2013 PrestaShop SA
 *  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

class EbaySynchronizer
{
	
	private static $ebay_categories = array();
	
	public static function syncProducts($products, $context, $id_lang) 
	{
		$date = date('Y-m-d H:i:s');
		$ebay = new EbayRequest();

		// Get errors back
		if (file_exists(dirname(__FILE__).'/../log/syncError.php')) 
			include(dirname(__FILE__).'/../log/syncError.php');

		$tab_error = array();

		// Up the time limit
		@set_time_limit(3600);
		
		$product_ids = array_map(function($product) {return $product['id_product'];}, $products);
		$products_configuration = EbayProductConfiguration::getByProductIds($product_ids);
		
		foreach ($products as $p) 
		{
			$product = new Product((int)$p['id_product'], true, $id_lang);
			
			// make sure that product exists in the db and has a default category
			if (!Validate::isLoadedObject($product) || !$product->id_category_default) 
				continue;

			$quantity_product = EbaySynchronizer::_getProductQuantity($product, (int)$p['id_product']);

			if (!$product->active || (isset($products_configuration[$product->id]) && $products_configuration[$product->id]['blacklisted']))
			{ // try to stop sale on eBay
				$ebay = EbaySynchronizer::endProductOnEbay($ebay, null, $product->id);
				if (!empty($ebay->error))
					$tab_error = EbaySynchronizer::_updateTabError($ebay->error, str_replace('&', '&amp;', $product->name));
				continue;
			}
			
			$ebay_category = EbaySynchronizer::_getEbayCategory($product->id_category_default);

			$pictures = EbaySynchronizer::_getPictures($product, $id_lang, $context, $products_configuration);
		
			// Load basic price
			list($price, $price_original) = EbaySynchronizer::_getPrices($product->id, $ebay_category->getPercent());
		
			// Generate array and try insert in database
			$data = array(
					'price' 						=> $price,
					'quantity' 					=> $quantity_product,
					'categoryId' 				=> $ebay_category->getIdCategoryRef(),
					'variations' 				=> EbaySynchronizer::_loadVariations($product, $context, $ebay_category),
					'pictures' 					=> $pictures['general'],
					'picturesMedium' 		=> $pictures['medium'],
					'picturesLarge' 		=> $pictures['large'],
					'condition'				  => EbaySynchronizer::_getEbayCondition($ebay_category->getConditionsValues(), $product),
					'shipping'					=> EbaySynchronizer::_getShippingDetailsForProduct($product),
			);
			$data = array_merge($data, EbaySynchronizer::_getProductData($product));

			// Fix hook update product
			if (Tools::getValue('id_product_attribute'))
			{
				$id_product_attribute_fix = (int)Tools::getValue('id_product_attribute');
				$key = $product_id.'-'.$id_product_attribute_fix;					
				if (isset($data['variations'][$key]['quantity']))
					$data['variations'][$key]['quantity'] = EbaySynchronizer::_fixHookUpdateProduct($context, $product_id, $data['variations'][$key]['quantity']);						
			}
			
			// Price Update
			if (isset($p['noPriceUpdate']))
				$data['noPriceUpdate'] = $p['noPriceUpdate'];

			$ebay_category->cleanPercent();

			// Save percent and price discount
			if ($ebay_category->getPercent() < 0) 
			{
				$data['price_original'] = round($price_original, 2);
				$data['price_percent'] = round($ebay_category->getPercent());
			}

			$data['description'] = EbaySynchronizer::_getEbayDescription($product, $id_lang);

			// Export to eBay
			$ebay = EbaySynchronizer::_exportProductToEbay($product, $data, $ebay_category, $ebay, $date, $id_lang);
			
			if (!empty($ebay->error)) // Check for errors
				$tab_error = EbaySynchronizer::_updateTabError($ebay->error, $data['name']);
		}

		if (count($tab_error))
		{
			if (isset($all_error))
				$tab_error = array_merge($all_error, $tab_error);
			file_put_contents(dirname(__FILE__).'/../log/syncError.php', '<?php $all_error = '.var_export($tab_error, true).'; '.($ebay->itemConditionError ? '$itemConditionError = true; ' : '$itemConditionError = false;').' ?>');
		}
	}
	
	private static function _getProductData($product)
	{
		return array(
			'id_product' 				=> $product->id,
			'reference' 				=> $product->reference,
			'name' 							=> str_replace('&', '&amp;', $product->name),
			'description' 			=> $product->description,
			'description_short' => $product->description_short,
		);
	}
	
	/**
	 * Exports the product to eBay and updates the ebay_product table
	 *
	 */
	private static function _exportProductToEbay($product, $data, $ebay_category, $ebay, $date, $id_lang)
	{
		if (count($data['variations']))
		{
			// the product is multivariation
			
			if ($ebay_category->isMultiSku() && EbaySynchronizer::_hasVariationsMatching($product->id, $id_lang))
			{
				// the category accepts multisku products and there is variables matching

				$data['item_specifics']	= EbaySynchronizer::_getProductItemSpecifics($ebay_category, $product, $id_lang);				
				$data['description'] = EbaySynchronizer::_getMultiSkuItemDescription($data);

				if ($item_id = EbayProduct::getIdProductRefByIdProduct($product->id)) //if product already exists on eBay
				{
					$data['itemID'] = $item_id;
					if (!EbaySynchronizer::_hasVariationProducts($data['variations']))
						EbaySynchronizer::endProductOnEbay($ebay, $item_id);
					else
						$ebay = EbaySynchronizer::_updateMultiSkuItem($product->id, $data, $ebay, $date);
				}
				else 
					EbaySynchronizer::_addMultiSkuItem($product->id, $data, $ebay, $date);
			}
			else 
			{
				// No Multi Sku case so we do multiple products from a multivariation product
				$data['item_specifics']	= EbaySynchronizer::_getProductItemSpecifics($ebay_category, $product, $id_lang, true);
				
				foreach ($data['variations'] as $variation)
				{
					$data_variation = EbaySynchronizer::_getVariationData($data, $variation);

					// Check if product exists on eBay
					if ($itemID = EbayProduct::getIdProductRefByIdProduct($product->id, $data_variation['id_attribute'])) 
					{
						$data_variation['itemID'] = $itemID;

						if ($data_variation['quantity'] < 1) // no more products 
							EbaySynchronizer::endProductOnEbay($ebay, $itemID);
						else 
							EbaySynchronizer::_updateItem($product->id, $data_variation, $ebay, $date, $data_variation['id_attribute']);
					}
					else 
						EbaySynchronizer::_addItem($product->id, $data_variation, $ebay, $date, $data_variation['id_attribute']);
				}
			}
		}
		else 
		{
			// the product is not a multivariation product
			
			$data['item_specifics']	= EbaySynchronizer::_getProductItemSpecifics($ebay_category, $product, $id_lang);			
			$data['description'] = EbaySynchronizer::_getItemDescription($data); 

			// Check if product exists on eBay
			if ($itemID = EbayProduct::getIdProductRefByIdProduct($product->id))
			{
				$data['itemID'] = $itemID;

				// Delete or Update
				if ($data['quantity'] < 1) 
					EbaySynchronizer::endProductOnEbay($ebay, $itemID);
				else
					EbaySynchronizer::_updateItem($product->id, $data, $ebay, $date);
			}
			else 
				EbaySynchronizer::_addItem($product->id, $data, $ebay, $date);
		}
		
		return $ebay;
	}
	
	private static function _hasVariationProducts($variations)
	{
		foreach ($variations as $variation)
			if ($variation['quantity'] >= 1)
				return true;
		return false;
	}
	
	private static function _addItem($product_id, $data, $ebay, $date, $id_attribute = 0)
	{
		$ebay->addFixedPriceItem($data);
		if ($ebay->itemID > 0)
			EbaySynchronizer::_insertEbayProduct($product_id, $ebay->itemID, $date, $id_attribute);
		
		return $ebay;		
	}
	
	private static function _updateItem($product_id, $data, $ebay, $date, $id_attribute = 0)
	{
		if ($ebay->reviseFixedPriceItem($data))
			EbayProduct::updateByIdProductRef($data['itemID'], array('date_upd' => pSQL($date)));

		// if product not on eBay as we expected we add it
		if ($ebay->errorCode == 291) 
		{
			// We delete from DB and Add it on eBay
			EbayProduct::deleteByIdProductRef($data['itemID']);
			EbaySynchronizer::_addItem($product_id, $data, $ebay, $date, $id_attribute);
		}
		
		return $ebay;		
	}
	
	private static function _addMultiSkuItem($product_id, $data, $ebay, $date)
	{
		$ebay->addFixedPriceItemMultiSku($data);
		if ($ebay->itemID > 0)
			EbaySynchronizer::_insertEbayProduct($product_id, $ebay->itemID, $date);
		
		return $ebay;
	}
	
	private static function _updateMultiSkuItem($product_id, $data, $ebay, $date)
	{
		if ($ebay->reviseFixedPriceItemMultiSku($data))
			EbayProduct::updateByIdProductRef($data['itemID'], array('date_upd' => pSQL($date)));

		// if product not on eBay as we expected we add it
		if ($ebay->errorCode == 291) 
		{
			// We delete from DB and Add it on eBay
			EbayProduct::deleteByIdProductRef($data['itemID']);
			$ebay = EbaySynchronizer::_addMultiSkuItem($product_id, $data, $ebay, $date);
		}
		
		return $ebay;
	}
		
	private static function _updateTabError($ebay_error, $name)
	{
		$error_key = md5($ebay_error);
		
		$tab_error[$error_key]['msg'] = $ebay_error;
	
		if (!isset($tab_error[$error_key]['products']))
				$tab_error[$error_key]['products'] = array();
	
		if (count($tab_error[$error_key]['products']) < 10)
				$tab_error[$error_key]['products'][] = $name;
	
		if (count($tab_error[$error_key]['products']) == 10)
				$tab_error[$error_key]['products'][] = '...';		
		
		return $tab_error;
	}
	
	private static function _getPictures($product, $id_lang, $context, $products_configuration)
	{
		$pictures = array();
		$pictures_medium = array();
		$pictures_large = array();
		
		$nb_pictures = 1 + (isset($products_configuration[$product->id]['extra_pictures']) ? $products_configuration[$product->id]['extra_pictures'] : 0);
		
		foreach (EbaySynchronizer::orderImages($product->getImages($id_lang)) as $image) 
		{
			$large_pict = EbaySynchronizer::_getPictureLink($product->id, $image['id_image'], $context->link, 'large');
			if (count($pictures) < $nb_pictures)
				$pictures[] = $large_pict;					
			
			$pictures_medium[] = EbaySynchronizer::_getPictureLink($product->id, $image['id_image'], $context->link, 'medium');
			$pictures_large[] = $large_pict;
		}
		
		return array(
			'general' => $pictures, 
			'medium'	=> $pictures_medium, 
			'large'   => $pictures_large);
	}
	
	private static function _getProductQuantity(Product $product, $id_product)
	{
		if (version_compare(_PS_VERSION_, '1.5', '<')) 
		{
			$product_for_quantity = new Product($id_product);
			$quantity_product = $product_for_quantity->quantity;
		}
		else
			$quantity_product = $product->quantity;

		return $quantity_product;
	}
	
	/**
	 * returns the eBay category object. Check if that has been loaded before
	 *
	 */
	private static function _getEbayCategory($category_id)
	{
		if (!isset(EbaySynchronizer::$ebay_categories[$category_id]))
			EbaySynchronizer::$ebay_categories[$category_id] = new EbayCategory(null, $category_id);
		
		return EbaySynchronizer::$ebay_categories[$category_id];
	}

	
	private static function _loadVariations($product, $context, $ebay_category)
	{
		$variations = array();

		$combinations = version_compare(_PS_VERSION_, '1.5', '>') ? $product->getAttributeCombinations($context->cookie->id_lang) : $product->getAttributeCombinaisons($context->cookie->id_lang);		

		foreach ($combinations as $combinaison) 
		{
			$price = Product::getPriceStatic((int)$combinaison['id_product'], true, (int)$combinaison['id_product_attribute']);

			$variation = array(
				'id_attribute' 	 => $combinaison['id_product_attribute'],
				'reference' 		 => $combinaison['reference'],
				'quantity' 			 => $combinaison['quantity'],
				'price_static'	 => $price,
				'variation_specifics' => EbaySynchronizer::_getVariationSpecifics($combinaison['id_product'], $combinaison['id_product_attribute'], $context->cookie->id_lang),
				'variations'		 => array(array(
						'name'  => $combinaison['group_name'], 
						'value' => $combinaison['attribute_name']))
			);
			
			$price_original = $price;						
			if (preg_match('#[-]{0,1}[0-9]{1,2}%$#is', $ebay_category->getPercent()))
				$price *= (1 + ($ebay_category->getPercent() / 100));
			else 
				$price += $ebay_category->getPercent();
			$variation['price'] = round($price, 2);
			
			if ($ebay_category->getPercent() < 0) 
			{
				$variation['price_original'] = round($price_original, 2);
				$variation['price_percent'] = round($ebay_category->getPercent());
			}
			
			$variation_key = $combinaison['id_product'].'-'.$combinaison['id_product_attribute'];				
			$variations[$variation_key] = $variation;
		}

		// Load Variations Pictures
		$combination_images = $product->getCombinationImages($context->cookie->id_lang); // RArbuz note: the lang was hardcorded to 2...
		if (!empty($combination_images))
			foreach ($combination_images as $combination_image)
				foreach ($combination_image as $image)
					$variations[$product->id.'-'.$image['id_product_attribute']]['pictures'][] = EbaySynchronizer::_getPictureLink($product->id, $image['id_image'], $context->link, 'large'); // if issue, it's because of https/http in the url		
		
		return $variations;
	}
	
	private static function _hasVariationsMatching($product_id, $id_lang)
	{
		$product = new Product($product_id); 
		$atribute_groups = $product->getAttributesGroups($id_lang);
		$attribute_group_ids = array_unique(array_map(function($row) { return $row['id_attribute_group']; }, $atribute_groups));
		
		$nb_variation_attribute_groups = Db::getInstance()->getValue('SELECT COUNT(*) 
			FROM `'._DB_PREFIX_.'ebay_category_specific`
			WHERE `can_variation` = 1 
			AND `id_attribute_group` IN ('.implode(', ', $attribute_group_ids).')');
		
		return (count($attribute_group_ids) == $nb_variation_attribute_groups);
	}
	
	private static function _getPrices($product_id, $percent)
	{
		$price = Product::getPriceStatic((int)$product_id, true);
		$price_original = $price;
		if (preg_match('#[-]{0,1}[0-9]{1,2}%$#is', $percent)) 
			$price *= (1 + ($percent / 100));
		else 
			$price += $percent;
		$price = round($price, 2);
		
		return array($price, $price_original);		
	}
	
	private static function _getEbayDescription($product, $id_lang)
	{
		$features_html = '';
		foreach ($product->getFrontFeatures((int)$id_lang) as $feature)
			$features_html .= '<b>'.$feature['name'].'</b> : '.$feature['value'].'<br/>';
	
		return str_replace(
					array(
						'{DESCRIPTION_SHORT}',
						'{DESCRIPTION}',
						'{FEATURES}',
						'{EBAY_IDENTIFIER}',
						'{EBAY_SHOP}',
						'{SLOGAN}',
						'{PRODUCT_NAME}'),
					array(
						$product->description_short,
						$product->description,
						$features_html,
						Configuration::get('EBAY_IDENTIFIER'),
						Configuration::get('EBAY_SHOP'),
						'', 
						$product->name),
						Configuration::get('EBAY_PRODUCT_TEMPLATE')
		);
	}
	
	public static function endProductOnEbay($ebay, $ebay_item_id, $product_id = null)
	{
		if (!$ebay_item_id)
			$ebay_item_id = EbayProduct::getIdProductRefByIdProduct($product_id);
		
		if ($ebay_item_id)
		{
			$ebay->endFixedPriceItem($ebay_item_id);
			EbayProduct::deleteByIdProductRef($ebay_item_id);
		}

		return $ebay;
	}
	
	private static function _getItemDescription($data)
	{
		return EbaySynchronizer::_fillDescription($data['description'], $data['picturesMedium'], $data['picturesLarge'], Tools::displayPrice($data['price']), isset($data['price_original']) ? 'au lieu de <del>'.Tools::displayPrice($data['price_original']).'</del> (remise de '.round($data['price_percent']).')' : '');
	}
	
	private static function _getMultiSkuItemDescription($data)
	{
		return EbaySynchronizer::_fillDescription($data['description'], $data['picturesMedium'], $data['picturesLarge'], '', '');		
	}
	
	private static function _fillDescription($description, $medium_pictures, $large_pictures, $product_price = '', $product_price_discount = '')
	{
		return str_replace(
				array('{MAIN_IMAGE}', '{MEDIUM_IMAGE_1}', '{MEDIUM_IMAGE_2}', '{MEDIUM_IMAGE_3}', '{PRODUCT_PRICE}', '{PRODUCT_PRICE_DISCOUNT}'), array(
			(isset($data['picturesLarge'][0]) ? '<img src="'.$data['picturesLarge'][0].'" class="bodyMainImageProductPrestashop" />' : ''),
			(isset($data['picturesMedium'][1]) ? '<img src="'.$data['picturesMedium'][1].'" class="bodyFirstMediumImageProductPrestashop" />' : ''),
			(isset($data['picturesMedium'][2]) ? '<img src="'.$data['picturesMedium'][2].'" class="bodyMediumImageProductPrestashop" />' : ''),
			(isset($data['picturesMedium'][3]) ? '<img src="'.$data['picturesMedium'][3].'" class="bodyMediumImageProductPrestashop" />' : ''),
			$product_price,
			$product_price_discount
				), $description
		);		
	}
	
	private static function _insertEbayProduct($id_product, $ebay_item_id, $date, $id_attribute = 0)
	{
		EbayProduct::insert(array(
			'id_country' 		 => 8, // NOTE RArbuz: why is this hardcoded?
			'id_product' 		 => (int)$id_product, 
			'id_attribute'	 => (int)$id_attribute, 
			'id_product_ref' => pSQL($ebay_item_id), 
			'date_add' 			 => pSQL($date), 
			'date_upd' 			 => pSQL($date)));	
	}
	
	private static function _getVariationData($data, $variation)
	{
		if (!empty($variation['pictures']))
				$data['pictures'] = $variation['pictures'];
		if (!empty($variation['picturesMedium']))
				$data['picturesMedium'] = $variation['picturesMedium'];
		if (!empty($variation['picturesLarge']))
				$data['picturesLarge'] = $variation['picturesLarge'];
		
		foreach ($variation['variations'] as $variation_label)
		{
			$data['name'] .= ' '.$variation_label['value'];
			$data['attributes'][$variation_label['name']] = $variation_label['value'];
		}
		$data['price'] = $variation['price'];
		if (isset($variation['price_original'])) 
		{
			$data['price_original'] = $variation['price_original'];
			$data['price_percent'] = $variation['price_percent'];
		}
		$data['quantity'] = $variation['quantity'];
		$data['id_attribute'] = $variation['id_attribute'];
		unset($data['variations']);
		//unset($data['variationsList']);

		// Load eBay Description
		$data['description'] = EbaySynchronizer::_fillDescription(
			$data['description'], 
			$data['picturesMedium'], 
			$data['picturesLarge'], 
			Tools::displayPrice($data['price']), 
			isset($data['price_original']) ? 'au lieu de <del>'.Tools::displayPrice($data['price_original']).'</del> (remise de '.round($data['price_percent']).')' : '');

		$data['id_product'] .= '-'.(int)$data['id_attribute'];
		
		$data['item_specifics'] = array_merge($data['item_specifics'], $variation['variation_specifics']);
		
		return $data;
	}

	private static function _getShippingDetailsForProduct($product)
	{
		$national_ship = array();
		$international_ship = array();

		//Get National Informations : service, costs, additional costs, priority
		$service_priority = 1; 
		foreach (EbayShipping::getNationalShippings() as $carrier) 
		{
			$national_ship[$carrier['ebay_carrier']] = array(
				'servicePriority' 			 => $service_priority, 
				'serviceAdditionalCosts' => $carrier['extra_fee'], 
				'serviceCosts' 					 => EbaySynchronizer::_getShippingPriceForProduct($product, Configuration::get('EBAY_ZONE_NATIONAL'), $carrier['ps_carrier'])
			);  
			$service_priority++;
		}


		//Get International Informations
		$service_priority = 1; 
		foreach (EbayShipping::getInternationalShippings() as $carrier) 
		{
			$international_ship[$carrier['ebay_carrier']] = array(
				'servicePriority' 			 => $service_priority, 
				'serviceAdditionalCosts' => $carrier['extra_fee'], 
				'serviceCosts' 					 => EbaySynchronizer::_getShippingPriceForProduct($product, Configuration::get('EBAY_ZONE_INTERNATIONAL'), $carrier['ps_carrier']),
				'locationsToShip' 			 => EbayShippingInternationalZone::getIdEbayZonesByIdEbayShipping($carrier['id_ebay_shipping'])
			); 
			$service_priority++;
		}

		return array(
			'excludedZone' 			=> EbayShippingZoneExcluded::getExcluded(),
			'nationalShip' 			=> $national_ship,
			'internationalShip' => $international_ship
		);
	}
	
	private static function _getPictureLink($id_product, $id_image, $context_link, $size)
	{
		//Fix for payment modules validating orders out of context, $link will not  generate fatal error.
		$link = is_object($context_link) ? $context_link : new Link();
		
		$prefix = (substr(_PS_VERSION_, 0, 3) == '1.3' ? Tools::getShopDomain(true).'/' : '');		
		
		return str_replace('https://', 'http://', $prefix.$link->getImageLink('ebay', $id_product.'-'.$id_image, $size.(version_compare(_PS_VERSION_, '1.5.1', '>=') ? '_default' : '')));		
	}

	private static function _getShippingPriceForProduct($product, $zone, $carrier_id)
	{
		$carrier = new Carrier($carrier_id);

		if (Configuration::get('PS_SHIPPING_METHOD') == 1) // Shipping by weight
			$price = $carrier->getDeliveryPriceByWeight($product->weight, $zone);
		else // Shipping by price
			$price = $carrier->getDeliveryPriceByPrice($product->price, $zone);
		
		if ($carrier->shipping_handling) //Add shipping handling fee
			$price += Configuration::get('PS_SHIPPING_HANDLING');

		$price += $price * Tax::getCarrierTaxRate($carrier_id) / 100;

		return $price;
	}
	
	public static function getNbSynchronizableProducts()
	{
		if (version_compare(_PS_VERSION_, '1.5', '>')) 
		{
			// Retrieve total nb products for eBay (which have matched categories)
			$nb_products = Db::getInstance()->getValue('
					SELECT COUNT( * ) FROM (
						SELECT COUNT(p.id_product) AS nb
							FROM  `'._DB_PREFIX_.'product` AS p
							INNER JOIN  `'._DB_PREFIX_.'stock_available` AS s ON p.id_product = s.id_product
							WHERE s.`quantity` >0
							AND  `id_category_default` 
							IN (
								SELECT  `id_category` 
								FROM  `'._DB_PREFIX_.'ebay_category_configuration` 
								WHERE  `id_ebay_category` > 0
								AND `id_ebay_category` > 0'.
								(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : ''). 
							')
							'.EbaySynchronizer::_addSqlRestrictionOnLang('s').'
							GROUP BY p.id_product
					)TableReponse');
		} 
		else
		{
			// Retrieve total nb products for eBay (which have matched categories)
			$nb_products = Db::getInstance()->getValue('
					SELECT COUNT(`id_product`)
					FROM `'._DB_PREFIX_.'product`
					WHERE `quantity` > 0 
					AND `id_category_default` IN (
						SELECT `id_category` 
						FROM `'._DB_PREFIX_.'ebay_category_configuration` 
						WHERE `id_category` > 0 
						AND `id_ebay_category` > 0'.
						(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : '').'
					)');
		}
		
		return $nb_products;
	}
	
	public static function getProductsToSynchronize($option)
	{
		if (version_compare(_PS_VERSION_, '1.5', '>')) 
		{
			$sql = '
				SELECT p.id_product
				FROM  `'._DB_PREFIX_.'product` AS p
					INNER JOIN  `'._DB_PREFIX_.'stock_available` AS s ON p.id_product = s.id_product
				WHERE s.`quantity` >0
				AND  `id_category_default` 
					IN (
						SELECT  `id_category` 
						FROM  `'._DB_PREFIX_.'ebay_category_configuration` 
						WHERE  `id_category` > 0
						AND  `id_ebay_category` > 0'.
						(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : '').
					')
				'.($option == 1 ? EbaySynchronizer::_addSqlCheckProductInexistence('p') : '').'
					AND p.`id_product` > '.(int)Configuration::get('EBAY_SYNC_LAST_PRODUCT').'
					'.EbaySynchronizer::_addSqlRestrictionOnLang('s').'
				ORDER BY  p.`id_product` 
				LIMIT 1';
		}
		else
		{
			$sql = '
				SELECT `id_product` 
				FROM `'._DB_PREFIX_.'product`
				WHERE `quantity` > 0 
				AND `id_category_default` IN (
					SELECT `id_category` 
					FROM `'._DB_PREFIX_.'ebay_category_configuration` 
					WHERE `id_category` > 0 
					AND `id_ebay_category` > 0'.
					(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : '').'
				)
				'.($option == 1 ? EbaySynchronizer::_addSqlCheckProductInexistence('p') : '').'
				AND `id_product` > '.(int)Configuration::get('EBAY_SYNC_LAST_PRODUCT').'
				ORDER BY `id_product`
				LIMIT 1';
		}		
		
		return Db::getInstance()->executeS($sql);
	}
	
	public static function getNbProductsLess($option, $ebay_sync_last_product)
	{
		if (version_compare(_PS_VERSION_, '1.5', '>')) 
		{
			$sql = '
				SELECT COUNT(id_supplier) FROM(
					SELECT id_supplier 
						FROM  `'._DB_PREFIX_.'product` AS p
							INNER JOIN  `'._DB_PREFIX_.'stock_available` AS s ON p.id_product = s.id_product
						WHERE s.`quantity` >0
						AND  `active` =1
						AND  `id_category_default` 
						IN (
							SELECT  `id_category` 
							FROM  `'._DB_PREFIX_.'ebay_category_configuration` 
							WHERE  `id_category` >0
							AND  `id_ebay_category` >0'.
							(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : '').								
						')
						'.(Tools::getValue('option') == 1 ? EbaySynchronizer::_addSqlCheckProductInexistence('p') : '').'
						AND p.`id_product` >'.$ebay_sync_last_product.'
						'.EbaySynchronizer::_addSqlRestrictionOnLang('s').'
						GROUP BY p.id_product
				)TableRequete';
		} 
		else 
		{
			$sql = '
				SELECT COUNT(`id_product`) 
				FROM `'._DB_PREFIX_.'product`
				WHERE `quantity` > 0 
				AND `active` = 1
				AND `id_category_default` IN (
					SELECT `id_category` 
					FROM `'._DB_PREFIX_.'ebay_category_configuration` 
					WHERE `id_category` > 0 
					AND `id_ebay_category` > 0'. 
					(Configuration::get('EBAY_SYNC_MODE') != 'A' ? ' AND `sync` = 1' : '').'
				)
				'.(Tools::getValue('option') == 1 ? EbaySynchronizer::_addSqlCheckProductInexistence('p') : '').'
				AND `id_product` > '.$ebay_sync_last_product;
		}
			
		return Db::getInstance()->getValue($sql);
	}
	
	private static function _getProductItemSpecifics($ebay_category, $product, $id_lang)
	{
		$item_specifics = $ebay_category->getItemsSpecificValues();
		
		$item_specifics_pairs = array();
		foreach ($item_specifics as $item_specific)
		{
			$value = null;
			if($item_specific['id_feature'])
				$value = EbaySynchronizer::_getFeatureValue($product->id, $item_specific['id_feature'], $id_lang);
			elseif($item_specific['is_brand'])
				$value = $product->manufacturer_name;
			else
				$value = $item_specific['specific_value'];
			
			if ($value)
				$item_specifics_pairs[$item_specific['name']] = $value;
		}
		
		return $item_specifics_pairs;
	}
	
	private static function _getAttributeValue($id_product, $id_attribute_group, $id_lang)
	{
		return Db::getInstance()->getValue('SELECT al.`name` 
			FROM `'._DB_PREFIX_.'attribute_lang` al
			INNER JOIN `'._DB_PREFIX_.'attribute` a
			ON al.`id_attribute` = a.`id_attribute`
			AND a.`id_attribute_group` = '.(int)$id_attribute_group.'
			INNER JOIN `'._DB_PREFIX_.'product_attribute_combination` pac
			ON a.`id_attribute` = pac.`id_attribute`
			INNER JOIN `'._DB_PREFIX_.'product_attribute` pa
			ON pac.`id_product_attribute` = pa.`id_product_attribute`
			AND pa.`id_product` = '.(int)$id_product.'
			WHERE al.`id_lang` = '.(int)$id_lang);
	}
	
	private static function _getFeatureValue($id_product, $id_feature, $id_lang)
	{
		return Db::getInstance()->getValue('SELECT fvl.`value`
			FROM `'._DB_PREFIX_.'feature_value_lang` fvl
			INNER JOIN `'._DB_PREFIX_.'feature_value` fv
			ON fvl.`id_feature_value` = fv.`id_feature_value`
			INNER JOIN `'._DB_PREFIX_.'feature_product` fp
			ON fv.`id_feature_value` = fp.`id_feature_value`	
			AND fp.`id_feature` = '.(int)$id_feature.'
			AND fp.`id_product` = '.(int)$id_product.'
			WHERE fvl.`id_lang` = '.(int)$id_lang);		
	}
	
	private static function _fixHookUpdateProduct($context, $product_id, $quantity)
	{
		if (isset($context->employee) 
			&& (int)$context->employee->id 
			&& Tools::getValue('submitProductAttribute') 
			&& Tools::getValue('attribute_mvt_quantity')
			&& Tools::getValue('id_mvt_reason')) 
		{
			$id_product_attribute_fix = (int)Tools::getValue('id_product_attribute');
			$key = $product_id.'-'.$id_product_attribute_fix;

			if (substr(_PS_VERSION_, 0, 3) == '1.3') 
			{
				$quantity_fix = (int)Tools::getValue('attribute_quantity');
				if ($id_product_attribute_fix > 0 && $quantity_fix > 0)
					$quantity = (int)$quantity_fix;
			}
			else 
			{
				$action = Db::getInstance()->getValue('SELECT `sign` 
					FROM `'._DB_PREFIX_.'stock_mvt_reason` 
					WHERE `id_stock_mvt_reason` = '.(int)Tools::getValue('id_mvt_reason'));
				$quantity_fix = (int)Tools::getValue('attribute_mvt_quantity');
				if ($id_product_attribute_fix > 0 
					&& $quantity_fix > 0 
					&& $action)
						$quantity += (int)$action * (int)$quantity_fix;
			}
		}
		
		return $quantity;
	}
	
	/**
	 *
	 * Returns the item specifics that correspond to a variation and not to the product in general
	 *
	 */
	public static function _getVariationSpecifics($product_id, $product_attribute_id, $id_lang)
	{
		$item_specifics_pairs = array();
		
		$attributes_values = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT IF(ecs.name <> null, ecs.name, agl.name) AS name, al.name AS value
			FROM '._DB_PREFIX_.'product_attribute_combination pac
			JOIN '._DB_PREFIX_.'attribute_lang al ON (pac.id_attribute = al.id_attribute AND al.id_lang='.(int)$id_lang.')
			JOIN '._DB_PREFIX_.'attribute a
			ON a.id_attribute = al.id_attribute			
			JOIN '._DB_PREFIX_.'attribute_group_lang agl
			ON a.id_attribute_group = agl.id_attribute_group
			AND agl.id_lang = '.(int)$id_lang.'
			LEFT JOIN '._DB_PREFIX_.'ebay_category_specific ecs
			ON a.id_attribute_group = ecs.id_attribute_group
			WHERE pac.id_product_attribute='.(int)$product_attribute_id);
			
		$item_specifics_pairs = array();
		foreach ($attributes_values as $attribute_value)
			$item_specifics_pairs[$attribute_value['name']] = $attribute_value['value'];
			
		return $item_specifics_pairs;
			/*
		foreach ($items_specifics as $item_specific)
		{
			if (!$item_specific['id_attribute_group'])
				continue;
			
			$value = Db::getInstance()->getValue('SELECT al.`name` 
				FROM `'._DB_PREFIX_.'attribute_lang` al
				INNER JOIN `'._DB_PREFIX_.'attribute` a
				ON al.`id_attribute` = a.`id_attribute`
				AND a.`id_attribute_group` = '.(int)$item_specific['id_attribute_group'].'
				INNER JOIN `'._DB_PREFIX_.'product_attribute_combination` pac
				ON a.`id_attribute` = pac.`id_attribute`
				AND pac.`id_product_attribute` = '.$product_attribute_id.'
				INNER JOIN `'._DB_PREFIX_.'product_attribute` pa
				ON pac.`id_product_attribute` = pa.`id_product_attribute`
				AND pa.`id_product` = '.(int)$product_id.'
				WHERE al.`id_lang` = '.(int)$id_lang);
				
			if ($value)
				$item_specifics_pairs[$item_specific['name']] = $value;
		}
			*/
			
			return $item_specifics_pairs;
	}
	
	private static function _getEbayCondition($conditions, $product)
	{
		return $conditions[$product->condition];
		// now there is no default value
		/*
		switch ($product->condition)
		{
			case 'new' :
				return Configuration::get('EBAY_CONDITION_NEW');
			case 'used' : 
				return Configuration::get('EBAY_CONDITION_USED');
			case 'refurbished' : 
				return Configuration::get('EBAY_CONDITION_REFURBISHED');
			default:
				return Configuration::get('EBAY_CONDITION_NEW');
		}
		*/
	}
	
	private static function _addSqlRestrictionOnLang($alias) 
	{
		if (version_compare(_PS_VERSION_, '1.5', '>'))
			Shop::addSqlRestrictionOnLang($alias);
	}

	private static function _addSqlCheckProductInexistence($alias = null)
	{
		return 'AND '.($alias ? $alias.'.' : '').'`id_product` NOT IN (
			SELECT `id_product` 
			FROM `'._DB_PREFIX_.'ebay_product`
		)';		
	}
	
	/**
	 * If there is a cover puts it at the top of the list
	 * otherwise returns the images in their position order
	 *
	 */
	private static function orderImages($images)
	{
		$covers = array();
		foreach ($images as $key => $image)
			if ($image['cover'])
			{
				$covers[] = $image;
				unset($images[$key]);
			}
		return array_merge($covers, $images);
	}
		
}