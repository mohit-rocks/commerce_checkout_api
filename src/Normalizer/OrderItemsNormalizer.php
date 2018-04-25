<?php

namespace Drupal\commerce_checkout_api\Normalizer;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
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
            if ($entity instanceof ProfileInterface || $entity instanceof OrderItemInterface || $entity instanceof PurchasableEntityInterface) return true;
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
            return $this->serializer->normalize($entity, $format, $context);
        }
        return $this->serializer->normalize([], $format, $context);
    }

}
