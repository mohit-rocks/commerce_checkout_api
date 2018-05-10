<?php

namespace Drupal\commerce_checkout_api\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\inline_entity_form\Form\EntityInlineForm;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Defines the inline form for product variations.
 */
class ProfileInlineForm extends EntityInlineForm
{

    /**
     * The loaded profile types.
     *
     * @var \Drupal\profile\Entity\ProfileTypeInterface[]
     */
    protected $profileTypes;

    /**
     * {@inheritdoc}
     */
    public function getEntityTypeLabels()
    {
        $labels = [
            'singular' => t('Profile'),
            'plural' => t('Profiles'),
        ];
        return $labels;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableFields($bundles)
    {
        //$fields = parent::getTableFields($bundles);
        $fields['field_address_province'] = [
            'type' => 'field',
            'label' => t('省份')
        ];
        $fields['field_address_city'] = [
            'type' => 'field',
            'label' => t('城市')
        ];
        $fields['field_address_district'] = [
            'type' => 'field',
            'label' => t('行政区')
        ];
        $fields['field_address_detail'] = [
            'type' => 'field',
            'label' => t('详细地址')
        ];
        $fields['field_name'] = [
            'type' => 'field',
            'label' => t('姓名')
        ];
        $fields['field_phone'] = [
            'type' => 'field',
            'label' => t('电话')
        ];

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityLabel(EntityInterface $entity)
    {
        /** @var ProfileInterface $entity */
        $type = $this->loadProfileType($entity->bundle());

        $label = 'Profile';
        if ($entity->bundle() === 'order_contact') {
            $label = '顾客信息';
        }

        return $label;
    }

    /**
     * @param $type_id
     * @return \Drupal\profile\Entity\ProfileTypeInterface
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     */
    protected function loadProfileType($type_id)
    {
        if (!isset($this->profileTypes[$type_id])) {
            $storage = $this->entityTypeManager->getStorage('profile_type');
            $this->profileTypes[$type_id] = $storage->load($type_id);
        }

        return $this->profileTypes[$type_id];
    }

}
