<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Action;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Feature\FeatureAvCreateEvent;
use Thelia\Core\Event\File\FileDeleteEvent;
use Thelia\Core\Event\Product\ProductCloneEvent;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvI18n;
use Thelia\Model\FeatureAvI18nQuery;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ProductDocument;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\Product as ProductModel;
use Thelia\Model\ProductAssociatedContent;
use Thelia\Model\ProductAssociatedContentQuery;
use Thelia\Model\ProductCategory;
use Thelia\Model\TaxRuleQuery;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\Accessory;
use Thelia\Model\FeatureProduct;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductDeleteEvent;
use Thelia\Core\Event\Product\ProductToggleVisibilityEvent;
use Thelia\Core\Event\Product\ProductAddContentEvent;
use Thelia\Core\Event\Product\ProductDeleteContentEvent;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Event\UpdateSeoEvent;
use Thelia\Core\Event\FeatureProduct\FeatureProductUpdateEvent;
use Thelia\Core\Event\FeatureProduct\FeatureProductDeleteEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Core\Event\Product\ProductDeleteCategoryEvent;
use Thelia\Core\Event\Product\ProductAddCategoryEvent;
use Thelia\Core\Event\Product\ProductAddAccessoryEvent;
use Thelia\Core\Event\Product\ProductDeleteAccessoryEvent;
use Propel\Runtime\Propel;

class Product extends BaseAction implements EventSubscriberInterface
{
    /**
     * Create a new product entry
     *
     * @param \Thelia\Core\Event\Product\ProductCreateEvent $event
     */
    public function create(ProductCreateEvent $event)
    {
        $product = new ProductModel();

        $product
            ->setDispatcher($event->getDispatcher())

            ->setRef($event->getRef())
            ->setLocale($event->getLocale())
            ->setTitle($event->getTitle())
            ->setVisible($event->getVisible() ? 1 : 0)
            ->setVirtual($event->getVirtual() ? 1 : 0)

            // Set the default tax rule to this product
            ->setTaxRule(TaxRuleQuery::create()->findOneByIsDefault(true))

            ->create(
                $event->getDefaultCategory(),
                $event->getBasePrice(),
                $event->getCurrencyId(),
                $event->getTaxRuleId(),
                $event->getBaseWeight()
            )
        ;

        // Set the product template, if one is defined in the category tree
        $parentCatId = $event->getDefaultCategory();

        while ($parentCatId > 0) {
            if (null === $cat = CategoryQuery::create()->findPk($parentCatId)) {
                break;
            }

            if ($cat->getDefaultTemplateId()) {
                $product->setTemplateId($cat->getDefaultTemplateId())->save();
                break;
            }

            $parentCatId = $cat->getParent();
        }

        $event->setProduct($product);
    }

    /*******************
     * CLONING PROCESS *
     *******************/

    /**
     * @param ProductCloneEvent $event
     * @throws \Exception
     */
    public function cloneProduct(ProductCloneEvent $event)
    {
        $con = Propel::getWriteConnection(ProductTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            // Get important datas
            $lang = $event->getLang();
            $originalProduct = $event->getOriginalProduct();
            $dispatcher = $event->getDispatcher();

            $originalProductDefaultI18n = ProductI18nQuery::create()
                ->findPk([$originalProduct->getId(), $lang]);

            $originalProductDefaultPrice = ProductPriceQuery::create()
                ->findOneByProductSaleElementsId($originalProduct->getDefaultSaleElements()->getId());

            // Cloning process

            $this->createClone($event, $originalProductDefaultI18n, $originalProductDefaultPrice);

            $this->updateClone($event, $originalProductDefaultPrice);

            $this->cloneFeatureCombination($event);

            $this->cloneAssociatedContent($event);

            // Dispatch event for file cloning
            $dispatcher->dispatch(TheliaEvents::FILE_CLONE, $event);

            // Dispatch event for PSE cloning
            $dispatcher->dispatch(TheliaEvents::PSE_CLONE, $event);

            $con->commit();
        } catch (\Exception $e) {
            $con->rollback();
            throw $e;
        }
    }

    public function createClone(ProductCloneEvent $event, $originalProductDefaultI18n, $originalProductDefaultPrice)
    {
        // Build event and dispatch creation of the clone product
        $createCloneEvent = new ProductCreateEvent();
        $createCloneEvent
            ->setTitle($originalProductDefaultI18n->getTitle())
            ->setRef($event->getRef())
            ->setLocale($event->getLang())
            ->setVisible(0)
            ->setVirtual($event->getOriginalProduct()->getVirtual())
            ->setTaxRuleId($event->getOriginalProduct()->getTaxRuleId())
            ->setDefaultCategory($event->getOriginalProduct()->getDefaultCategoryId())
            ->setBasePrice($originalProductDefaultPrice->getPrice())
            ->setCurrencyId($originalProductDefaultPrice->getCurrencyId())
            ->setBaseWeight($event->getOriginalProduct()->getDefaultSaleElements()->getWeight())
            ->setDispatcher($event->getDispatcher());

        $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_CREATE, $createCloneEvent);

        $event->setClonedProduct($createCloneEvent->getProduct());
    }

    public function updateClone(ProductCloneEvent $event, $originalProductDefaultPrice)
    {
        // Get original product's I18ns
        $originalProductI18ns = ProductI18nQuery::create()
            ->findById($event->getOriginalProduct()->getId());

        foreach ($originalProductI18ns as $originalProductI18n) {
            $clonedProductUpdateEvent = new ProductUpdateEvent($event->getClonedProduct()->getId());
            $clonedProductUpdateEvent
                ->setRef($event->getClonedProduct()->getRef())
                ->setVisible($event->getClonedProduct()->getVisible())
                ->setVirtual($event->getClonedProduct()->getVirtual())

                ->setLocale($originalProductI18n->getLocale())
                ->setTitle($originalProductI18n->getTitle())
                ->setChapo($originalProductI18n->getChapo())
                ->setDescription($originalProductI18n->getDescription())
                ->setPostscriptum($originalProductI18n->getPostscriptum())

                ->setBasePrice($originalProductDefaultPrice->getPrice())
                ->setCurrencyId($originalProductDefaultPrice->getCurrencyId())
                ->setBaseWeight($event->getOriginalProduct()->getDefaultSaleElements()->getWeight())
                ->setTaxRuleId($event->getOriginalProduct()->getTaxRuleId())
                ->setBrandId($event->getOriginalProduct()->getBrandId())
                ->setDefaultCategory($event->getOriginalProduct()->getDefaultCategoryId());

            $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_UPDATE, $clonedProductUpdateEvent);

            // SEO info
            $clonedProductUpdateSeoEvent = new UpdateSeoEvent($event->getClonedProduct()->getId());
            $clonedProductUpdateSeoEvent
                ->setLocale($originalProductI18n->getLocale())
                ->setMetaTitle($originalProductI18n->getMetaTitle())
                ->setMetaDescription($originalProductI18n->getMetaDescription())
                ->setMetaKeywords($originalProductI18n->getMetaKeywords())
                ->setUrl(null);
            $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_UPDATE_SEO, $clonedProductUpdateSeoEvent);
        }

        $event->setClonedProduct($clonedProductUpdateEvent->getProduct());

        // Set clone's template
        $clonedProductUpdateTemplateEvent = new ProductSetTemplateEvent(
            $event->getClonedProduct(),
            $event->getOriginalProduct()->getTemplateId(),
            $originalProductDefaultPrice->getCurrencyId()
        );
        $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_SET_TEMPLATE, $clonedProductUpdateTemplateEvent);
    }

    public function cloneFeatureCombination(ProductCloneEvent $event)
    {
        // Get original product features
        $originalProductFeatures = FeatureProductQuery::create()
            ->findByProductId($event->getOriginalProduct()->getId());

        // Set clone product features
        foreach ($originalProductFeatures as $originalProductFeature) {
            // Check if the feature value is a text one or not
            if ($originalProductFeature->getFeatureAvId() == null && $originalProductFeature->getFreeTextValue() != null) {
                $value = $originalProductFeature->getFreeTextValue();
            } elseif ($originalProductFeature->getFeatureAvId() != null && $originalProductFeature->getFreeTextValue() == null) {
                $value = $originalProductFeature->getFeatureAvId();
            } else {
                throw new \Exception('Feature value is not defined');
            }

            $clonedProductCreateFeatureEvent = new FeatureProductUpdateEvent(
                $event->getClonedProduct()->getId(),
                $originalProductFeature->getFeatureId(),
                $value
            );

            if ($originalProductFeature->getFeatureAvId() == null && $originalProductFeature->getFreeTextValue() != null) {
                $clonedProductCreateFeatureEvent->setIsTextValue(true);
            }

            $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE, $clonedProductCreateFeatureEvent);
        }
    }

    public function cloneAssociatedContent(ProductCloneEvent $event)
    {
        // Get original product associated contents
        $originalProductAssocConts = ProductAssociatedContentQuery::create()
            ->findByProductId($event->getOriginalProduct()->getId());

        // Set clone product associated contents
        foreach ($originalProductAssocConts as $originalProductAssocCont) {
            $clonedProductCreatePAC = new ProductAddContentEvent($event->getClonedProduct(), $originalProductAssocCont->getContentId());
            $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_ADD_CONTENT, $clonedProductCreatePAC);
        }
    }

    /***************
     * END CLONING *
     ***************/

    /**
     * Change a product
     *
     * @param \Thelia\Core\Event\Product\ProductUpdateEvent $event
     */
    public function update(ProductUpdateEvent $event)
    {
        if (null !== $product = ProductQuery::create()->findPk($event->getProductId())) {
            $con = Propel::getWriteConnection(ProductTableMap::DATABASE_NAME);
            $con->beginTransaction();

            try {
                $product
                    ->setDispatcher($event->getDispatcher())
                    ->setRef($event->getRef())
                    ->setLocale($event->getLocale())
                    ->setTitle($event->getTitle())
                    ->setDescription($event->getDescription())
                    ->setChapo($event->getChapo())
                    ->setPostscriptum($event->getPostscriptum())
                    ->setVisible($event->getVisible() ? 1 : 0)
                    ->setVirtual($event->getVirtual() ? 1 : 0)
                    ->setBrandId($event->getBrandId() <= 0 ? null : $event->getBrandId())

                    ->save($con)
                ;

                // Update default category (if required)
                $product->updateDefaultCategory($event->getDefaultCategory());

                $event->setProduct($product);
                $con->commit();
            } catch (PropelException $e) {
                $con->rollBack();
                throw $e;
            }
        }
    }

    /**
     * Change a product SEO
     *
     * @param \Thelia\Core\Event\UpdateSeoEvent $event
     */
    public function updateSeo(UpdateSeoEvent $event)
    {
        return $this->genericUpdateSeo(ProductQuery::create(), $event);
    }

    /**
     * Delete a product entry
     *
     * @param \Thelia\Core\Event\Product\ProductDeleteEvent $event
     */
    public function delete(ProductDeleteEvent $event)
    {
        if (null !== $product = ProductQuery::create()->findPk($event->getProductId())) {
            $product
                ->setDispatcher($event->getDispatcher())
                ->delete()
            ;

            $event->setProduct($product);
        }
    }

    /**
     * Toggle product visibility. No form used here
     *
     * @param ActionEvent $event
     */
    public function toggleVisibility(ProductToggleVisibilityEvent $event)
    {
        $product = $event->getProduct();

        $product
            ->setDispatcher($event->getDispatcher())
            ->setVisible($product->getVisible() ? false : true)
            ->save()
            ;

        $event->setProduct($product);
    }

    /**
     * Changes position, selecting absolute ou relative change.
     *
     * @param ProductChangePositionEvent $event
     */
    public function updatePosition(UpdatePositionEvent $event)
    {
        $this->genericUpdatePosition(ProductQuery::create(), $event);
    }

    public function addContent(ProductAddContentEvent $event)
    {
        if (ProductAssociatedContentQuery::create()
            ->filterByContentId($event->getContentId())
             ->filterByProduct($event->getProduct())->count() <= 0) {
            $content = new ProductAssociatedContent();

            $content
                ->setDispatcher($event->getDispatcher())
                ->setProduct($event->getProduct())
                ->setContentId($event->getContentId())
                ->save()
            ;
        }
    }

    public function removeContent(ProductDeleteContentEvent $event)
    {
        $content = ProductAssociatedContentQuery::create()
            ->filterByContentId($event->getContentId())
            ->filterByProduct($event->getProduct())->findOne()
        ;

        if ($content !== null) {
            $content
                ->setDispatcher($event->getDispatcher())
                ->delete()
            ;
        }
    }

    public function addCategory(ProductAddCategoryEvent $event)
    {
        if (ProductCategoryQuery::create()
            ->filterByProduct($event->getProduct())
            ->filterByCategoryId($event->getCategoryId())
            ->count() <= 0) {
            $productCategory = new ProductCategory();

            $productCategory
                ->setProduct($event->getProduct())
                ->setCategoryId($event->getCategoryId())
                ->setDefaultCategory(false)
                ->save()
            ;
        }
    }

    public function removeCategory(ProductDeleteCategoryEvent $event)
    {
        $productCategory = ProductCategoryQuery::create()
            ->filterByProduct($event->getProduct())
            ->filterByCategoryId($event->getCategoryId())
            ->findOne();

        if ($productCategory != null) {
            $productCategory->delete();
        }
    }

    public function addAccessory(ProductAddAccessoryEvent $event)
    {
        if (AccessoryQuery::create()
            ->filterByAccessory($event->getAccessoryId())
            ->filterByProductId($event->getProduct()->getId())->count() <= 0) {
            $accessory = new Accessory();

            $accessory
                ->setDispatcher($event->getDispatcher())
                ->setProductId($event->getProduct()->getId())
                ->setAccessory($event->getAccessoryId())
            ->save()
            ;
        }
    }

    public function removeAccessory(ProductDeleteAccessoryEvent $event)
    {
        $accessory = AccessoryQuery::create()
            ->filterByAccessory($event->getAccessoryId())
            ->filterByProductId($event->getProduct()->getId())->findOne()
        ;

        if ($accessory !== null) {
            $accessory
                ->setDispatcher($event->getDispatcher())
                ->delete()
            ;
        }
    }

    public function setProductTemplate(ProductSetTemplateEvent $event)
    {
        $con = Propel::getWriteConnection(ProductTableMap::DATABASE_NAME);

        $con->beginTransaction();

        try {
            $product = $event->getProduct();

            // Delete all product feature relations
            if (null !== $featureProducts = FeatureProductQuery::create()->findByProductId($product->getId())) {
                /** @var \Thelia\Model\FeatureProduct $featureProduct */
                foreach ($featureProducts as $featureProduct) {
                    $eventDelete = new FeatureProductDeleteEvent($product->getId(), $featureProduct->getFeatureId());

                    $event->getDispatcher()->dispatch(TheliaEvents::PRODUCT_FEATURE_DELETE_VALUE, $eventDelete);
                }
            }

            // Delete all product attributes sale elements
            AttributeCombinationQuery::create()
                ->filterByProductSaleElements($product->getProductSaleElementss())
                ->delete($con)
            ;

            //Delete all productSaleElements except the default one (to keep price, weight, ean, etc...)
            ProductSaleElementsQuery::create()
                ->filterByProduct($product)
                ->filterByIsDefault(1, Criteria::NOT_EQUAL)
                ->delete($con)
            ;

            // Update the product template
            $template_id = $event->getTemplateId();

            // Set it to null if it's zero.
            if ($template_id <= 0) {
                $template_id = null;
            }

            $product->setTemplateId($template_id)->save($con);

            //Be sure that the product has a default productSaleElements
            /** @var \Thelia\Model\ProductSaleElements $defaultPse */
            if (null == $defaultPse = ProductSaleElementsQuery::create()
                    ->filterByProduct($product)
                    ->filterByIsDefault(1)
                    ->findOne()) {
                // Create a new default product sale element
                $product->createProductSaleElement($con, 0, 0, 0, $event->getCurrencyId(), true);
            }

            $product->clearProductSaleElementss();

            $event->setProduct($product);

            // Store all the stuff !
            $con->commit();
        } catch (\Exception $ex) {
            $con->rollback();

            throw $ex;
        }
    }

    /**
     * Changes accessry position, selecting absolute ou relative change.
     *
     * @param ProductChangePositionEvent $event
     */
    public function updateAccessoryPosition(UpdatePositionEvent $event)
    {
        return $this->genericUpdatePosition(AccessoryQuery::create(), $event);
    }

    /**
     * Changes position, selecting absolute ou relative change.
     *
     * @param ProductChangePositionEvent $event
     */
    public function updateContentPosition(UpdatePositionEvent $event)
    {
        return $this->genericUpdatePosition(ProductAssociatedContentQuery::create(), $event);
    }

    /**
     * Update the value of a product feature.
     *
     * @param FeatureProductUpdateEvent $event
     */
    public function updateFeatureProductValue(FeatureProductUpdateEvent $event)
    {
        // If the feature is not free text, it may have one or more values.
        // If the value exists, we do not change it.
        // If the value does not exist, we create it.
        //
        // If the feature is free text, it has only a single value.
        // Else create or update it.

        $featureProductQuery = FeatureProductQuery::create()
            ->filterByProductId($event->getProductId())
            ->filterByFeatureId($event->getFeatureId())
        ;

        // FeatureId is enough to find unique FeatureProduct with FreeTextValue
        // Moreover, FeatureAvId might not exist at the creation of the FreeTextValue
        if ($event->getIsTextValue() != true) {
            $featureProductQuery->filterByFeatureAvId($event->getFeatureValue());
        }

        $featureProduct = $featureProductQuery->findOne();

        // If the FeatureProduct does not exist
        if ($featureProduct == null) {
            $featureProduct = new FeatureProduct();

            $featureProduct
                ->setDispatcher($event->getDispatcher())
                ->setProductId($event->getProductId())
                ->setFeatureId($event->getFeatureId())
            ;

            // If it's a free_text_value, create a feature_av to handle i18n
            if ($event->getIsTextValue() == true) {
                $featureProduct->setFreeTextValue(true);

                $createFeatureAvEvent = new FeatureAvCreateEvent();
                $createFeatureAvEvent
                    ->setFeatureId($event->getFeatureId())
                    ->setLocale($event->getLocale())
                    ->setTitle($event->getFeatureValue());
                $event->getDispatcher()->dispatch(TheliaEvents::FEATURE_AV_CREATE, $createFeatureAvEvent);

                $featureAvId = $createFeatureAvEvent->getFeatureAv()->getId();
            }
        } // Else if the FeatureProduct exists and is a free text value
        elseif ($featureProduct != null && $event->getIsTextValue() == true) {

            // Get the Feature's FeatureAv
            $freeTextFeatureAv = FeatureAvQuery::create()
                ->findOneByFeatureId($event->getFeatureId());

            // Get the FeatureAv's FeatureAvI18n by locale
            $freeTextFeatureAvI18n = FeatureAvI18nQuery::create()
                ->filterById($freeTextFeatureAv->getId())
                ->findOneByLocale($event->getLocale());

            // If $freeTextFeatureAvI18n is null, no corresponding i18n exist, so create a FeatureAvI18n
            if ($freeTextFeatureAvI18n == null) {
                $featureAvI18n = new FeatureAvI18n();
                $featureAvI18n
                    ->setId($freeTextFeatureAv->getId())
                    ->setLocale($event->getLocale())
                    ->setTitle($event->getFeatureValue())
                    ->save();

                $featureAvId = $featureAvI18n->getId();
            } else {
                // Else update the existing one
                $freeTextFeatureAvI18n
                    ->setTitle($event->getFeatureValue())
                    ->save();

                $featureAvId = $freeTextFeatureAvI18n->getId();
            }
        } // Else the FeatureProduct exists and is not a free text value
        else {
            $featureAvId = $event->getFeatureValue();
        }

        $featureProduct->setFeatureAvId($featureAvId);

        $featureProduct->save();

        $event->setFeatureProduct($featureProduct);
    }

    /**
     * Delete a product feature value
     *
     * @param FeatureProductDeleteEvent $event
     */
    public function deleteFeatureProductValue(FeatureProductDeleteEvent $event)
    {
        FeatureProductQuery::create()
            ->filterByProductId($event->getProductId())
            ->filterByFeatureId($event->getFeatureId())
            ->delete()
        ;
    }

    public function deleteImagePSEAssociations(FileDeleteEvent $event)
    {
        $model = $event->getFileToDelete();

        if ($model instanceof ProductImage) {
            $model->getProductSaleElementsProductImages()->delete();
        }
    }

    public function deleteDocumentPSEAssociations(FileDeleteEvent $event)
    {
        $model = $event->getFileToDelete();

        if ($model instanceof ProductDocument) {
            $model->getProductSaleElementsProductDocuments()->delete();
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::PRODUCT_CREATE                    => array("create", 128),
            TheliaEvents::PRODUCT_CLONE                     => array("cloneProduct", 128),
            TheliaEvents::PRODUCT_UPDATE                    => array("update", 128),
            TheliaEvents::PRODUCT_DELETE                    => array("delete", 128),
            TheliaEvents::PRODUCT_TOGGLE_VISIBILITY         => array("toggleVisibility", 128),

            TheliaEvents::PRODUCT_UPDATE_POSITION           => array("updatePosition", 128),
            TheliaEvents::PRODUCT_UPDATE_SEO                => array("updateSeo", 128),

            TheliaEvents::PRODUCT_ADD_CONTENT               => array("addContent", 128),
            TheliaEvents::PRODUCT_REMOVE_CONTENT            => array("removeContent", 128),
            TheliaEvents::PRODUCT_UPDATE_CONTENT_POSITION   => array("updateContentPosition", 128),

            TheliaEvents::PRODUCT_ADD_ACCESSORY             => array("addAccessory", 128),
            TheliaEvents::PRODUCT_REMOVE_ACCESSORY          => array("removeAccessory", 128),
            TheliaEvents::PRODUCT_UPDATE_ACCESSORY_POSITION => array("updateAccessoryPosition", 128),

            TheliaEvents::PRODUCT_ADD_CATEGORY              => array("addCategory", 128),
            TheliaEvents::PRODUCT_REMOVE_CATEGORY           => array("removeCategory", 128),

            TheliaEvents::PRODUCT_SET_TEMPLATE              => array("setProductTemplate", 128),

            TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE      => array("updateFeatureProductValue", 128),
            TheliaEvents::PRODUCT_FEATURE_DELETE_VALUE      => array("deleteFeatureProductValue", 128),

            // Those two have to be executed before
            TheliaEvents::IMAGE_DELETE                      => array("deleteImagePSEAssociations", 192),
            TheliaEvents::DOCUMENT_DELETE                   => array("deleteDocumentPSEAssociations", 192),
        );
    }
}
