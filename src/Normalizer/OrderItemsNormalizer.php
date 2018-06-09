<?php

namespace Drupal\commerce_checkout_api\Normalizer;

use Drupal\aiqilv_order_verification\Entity\OrderVerificationCode;
use Drupal\aiqilv_order_verification\Entity\OrderVerificationCodeInterface;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\serialization\Normalizer\EntityReferenceFieldItemNormalizer;

/**
 * Expands order items to their referenced entity.
 */
class OrderItemsNormalizer extends EntityReferenceFieldItemNormalizer {

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = NULL) {
        $supported = parent::supportsNormalization($data, $format);
        if ($data instanceof EntityReferenceItem) {
            $entity = $data->get('entity')->getValue();
            if ($entity instanceof ProfileInterface || $entity instanceof OrderItemInterface || $entity instanceof PurchasableEntityInterface || $entity instanceof OrderVerificationCodeInterface) return true;
        }
        return FALSE;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($field_item, $format = NULL, array $context = []) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        /** @var \Drupal\Core\Entity\EntityInterface $entity */
        if ($entity = $field_item->get('entity')->getValue()) {
            if ($entity instanceof PurchasableEntityInterface) {
                $data = $this->serializer->normalize($entity, $format, $context);
                $variation = null;
                if ($entity instanceof ProductVariationInterface) {
                    $variation = $entity;
                } else {
                    try {
                        $variation = $entity->getVariation();
                    } catch (\Exception $e) {
                        $variation = null;
                    }
                }

                if ($variation) {
                    $data['_product']['id'] = $variation->getProduct()->id();
                    $data['_product']['name'] = $variation->getProduct()->getTitle();
                    $data['_product']['image'] = $variation->getProduct()->hasField('image') && $variation->getProduct()->get('image')->entity ? file_create_url($variation->getProduct()->get('image')->entity->getFileUri()) : '';
                }
                return $data;
            } else {
                return $this->serializer->normalize($entity, $format, $context);
            }
        }
        return $this->serializer->normalize([], $format, $context);
    }

}
