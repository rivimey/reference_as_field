<?php

namespace Drupal\reference_as_field\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\{
  Display\EntityViewDisplayInterface,
  EntityInterface,
  EntityTypeManagerInterface,
  EntityManager
};
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Field\{
  FieldDefinitionInterface,
  FieldItemInterface,
  FieldItemList,
  FieldItemListInterface,
  FormatterBase
};
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\typed_data\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;

/**
 * Plugin implementation of the reference_as_field formatter.
 *
 * @FieldFormatter(
 *   id = "merge_into_content_formatter",
 *   label = @Translation("Fields in parent content"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions",
 *     "dynamic_entity_reference"
 *   }
 * )
 */
class MergeReferenceIntoContentFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * @var EntityTypeManager
   */
  protected $entityManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a MergeReferenceIntoContentFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param EntityTypeManagerInterface $entity_manager
   * @param LoggerChannelFactoryInterface $logger_factory
   *   A logger instance.
   */
  public function __construct($plugin_id,
                              $plugin_definition,
                              FieldDefinitionInterface $field_definition,
                              array $settings,
                              $label,
                              $view_mode,
                              array $third_party_settings,
                              EntityTypeManagerInterface $entity_manager,
                              LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityManager = $entity_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Create the display settings subform for the Entity Display form.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return array
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $view_modes = $this->getConfigurableViewModes();
    if (!empty($view_modes)) {
      $elements['view_mode'] = [
        '#title' => t('View Mode'),
        '#description' => t('Select the view mode which will control which fields are shown and the display settings of those fields.'),
        '#type' => 'select',
        '#default_value' => $this->getSettings()['view_mode'],
        '#options' => $view_modes,
      ];
    }

    $elements['show_entity_label'] = [
      '#title' => t('Display Entity Label'),
      '#description' => t('Should the label of the target entity be displayed in the table?'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('show_entity_label'),
    ];

    return $elements;
  }

  /**
   * Return the settings summary (text) for a collapsed field Entity Display form.
   *
   * @return array|string[]
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Render with @view_mode view mode.',
                          ['@view_mode' => $this->getSetting('view_mode')]);

    if ($this->getSetting('show_entity_label')) {
      $summary[] = $this->t('With a label.');
    }
    else {
      $summary[] = $this->t('Without a label.');
    }
    return $summary;
  }

  /**
   * @param FieldItemListInterface $items
   * @param string $langcode
   *
   * @return array
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $output = [];
    if ($items->count() == 0) {
      return $output;
    }
    $entities = $this->getEntitiesForViewing($items, $langcode);
    if ($entities) {
      $output = $this->getRenderArray($entities);
      $this->buildCacheMetadata($entities)->applyTo($output);
    }
    return $output;
  }

  /**
   *
   * @param string $langcode
   * @param FieldItemListInterface $items
   *
   * @return EntityInterface[]
   *
   * @todo Deal with langcode!
   */
  public function getEntitiesForViewing(FieldItemListInterface $items, $langcode = '') {
    try {
      $entity_type = $this->getTargetEntityId($this->fieldDefinition);
      if (!$entity_type) {
        $this->loggerFactory->get('reference_as_field')
          ->error($this->t('Could not find target entity in @name (@type).', [
            '@name' => $this->fieldDefinition->getName(),
            '@type' => $this->fieldDefinition->getType()
          ]));
        return [];
      }
      $entity_storage = $this->entityManager->getStorage($entity_type);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $pnfe) { // PHP >=7.1
      $this->loggerFactory->get('reference_as_field')
        ->error($this->t('Bad Plugin / not found: @mess', ['@mess' => $pnfe->getMessage()]));
      return [];
    }

    $entities = [];
    foreach ($items as $item) {
      $entity = $entity_storage->load($this->getEntityIdFromFieldItem($item));
      if (isset($entity) && $entity->access('view')) {
        $entities[] = $entity;
      }
    }
    return $entities;
  }

  /**
   * Return the view renderer for the entity
   *
   * @param EntityInterface $entity
   * @param string $view_mode
   *
   * @return \Drupal\Core\Entity\Display\EntityViewDisplayInterface|\Drupal\Core\Entity\EntityInterface|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getRendererForEntity($entity, $view_mode) {

    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $part = "{$entity_type_id}.{$bundle}";

    /** @var ConfigEntityStorageInterface $storage */
    $storage = $this->entityManager->getStorage('entity_view_display');

    /** @var EntityViewDisplayInterface $renderer */
    $renderer = $storage->load("{$part}.{$view_mode}");
    if (!$renderer) {
      $renderer = $storage->load("{$part}.default");
    }

    if ($renderer === NULL) {
      $this->loggerFactory->get('reference_as_field')
        ->error($this->t('Unable to render referenced entities: renderer not found for @type.@bundle.' . $view_mode, [
          '@type' => $entity_type_id,
          '@bundle' => $bundle
        ]));
    }
    return $renderer;
  }

  /**
   * Get the render array for the given entities.
   *
   * @param $entities
   *   The loaded entities.
   *
   * @return array
   *   A Render array.
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function getRenderArray($entities) {
    $view_mode = $this->getSetting('view_mode');

    $render_list = [];
    foreach ($entities as $key => $entity) {
      $renderer = $this->getRendererForEntity($entity, $view_mode);
      if ($renderer) {
        $rendered = $renderer->build($entity);

        if (!$this->getSetting('show_entity_label')) {

          // Get the base entity definition to find out which field, if any,
          // is the entity label... e.g. "Node" label is "title".
          $entityDefinition = $this->entityManager->getDefinition($entity->getEntityTypeId());
          $labelField = $entityDefinition->getKey('label');
          if ($labelField) {
            unset($rendered[$labelField]);
          }
        }
        uasort($rendered, [SortArray::class, 'sortByWeightProperty',]);

        $render_list[$key] = $rendered;
      }
    }

    return $render_list;
  }

  /**
   * @param array $entities
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   */
  public function buildCacheMetadata($entities) {
    $cache_metadata = new CacheableMetadata();
    foreach ($entities as $entity) {
      // @todo check if the entity is needed for viewing so the cache tags make some sense
      $cache_metadata->addCacheableDependency($entity);
      $cache_metadata->addCacheableDependency($entity->access('view', NULL, TRUE));
    }
    return $cache_metadata;

  }

  /**
   * @param FieldDefinitionInterface $field_definition
   *
   * @return array|mixed
   * @throws InvalidArgumentException
   */
  protected function getTargetBundleId(FieldDefinitionInterface $field_definition) {
    return $field_definition->getTargetBundle();
  }

  /**
   * Check if the field is renderable.
   *
   * @param array $field
   *
   * @return bool
   * @todo Check if this actually is enough.
   *
   */
  protected function fieldIsRenderableContent($field) {
    /** @var FieldItemList $field ['#items'] */
    return (
      isset($field['#items'])
      && $field['#items']->getFieldDefinition()->getDisplayOptions('view')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getConfigurableViewModes() {
    return $this->entityManager->getViewModeOptions($this->getTargetEntityId($this->fieldDefinition));
  }

  /**
   * @param FieldDefinitionInterface $field_definition
   *
   * @return mixed
   */
  protected function getTargetEntityId(FieldDefinitionInterface $field_definition) {
    $storage_def = $field_definition->getFieldStorageDefinition();
    if ($storage_def) {
      // entity_reference:
      $target = $storage_def->getSetting('target_type');
      if ($target) {
        return $target;
      }
      // dynamic_entity_reference:
      $target = $storage_def->getSetting('entity_type_ids');
      if ($target) {
        return reset($target);
      }
    }
    $this->loggerFactory->get('reference_as_field')
      ->error($this->t('No target entity type specified for field @name (@type).', [
        '@name' => $field_definition->getName(),
        '@type' => $field_definition->getType()
      ]));
  }

  /**
   * @param FieldItemInterface $item
   *
   * @return mixed
   */
  protected function getEntityIdFromFieldItem(FieldItemInterface $item) {
    return $item->getValue()['target_id'];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'view_mode' => 'default',
      'show_entity_label' => 0,
    ];
  }
}
