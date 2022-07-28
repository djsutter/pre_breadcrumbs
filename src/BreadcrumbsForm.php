<?php

namespace Drupal\pre_breadcrumbs;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Breadcrumbs form class, handles dynamic add/remove items.
 */
class BreadcrumbsForm extends ConfigFormBase {

  /**
   * The language manager object.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * The config factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Class constructor.
   */
  public function __construct(LanguageManager $languageManager, ConfigFactory $configFactory) {
    $this->languageManager = $languageManager;
    $this->configFactory = $configFactory;
  }

  /**
   * Create for injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Injection container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pre_breadcrumbs_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return 'pre_breadcrumbs.settings';
  }

  // @todo form layout side-by-side columns for FR and EN

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $config = $this->configFactory->get('pre_breadcrumbs.settings');

    $form = [];

    $form['description'] = [
      '#markup' => '<div>' . $this->t('Add and remove leading breadcrumbs using the buttons below.') . '</div>',
    ];

    $form['front_page_crumb'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display current page crumb on the front page.'),
      '#default_value' => $config->get('front_page_crumb') ?? TRUE,
    ];

    // Determine the current number of breadcrumbs.
    // Example from: https://drupal.stackexchange.com/a/200972
    $n = 0;
    $curr = $form_state->get('num_crumbs');
    if (empty($curr) && $config->get('en')) {
      foreach ($config->get('en') as $key => $val) {
        if (is_numeric($key) && isset($val['en_crumb']) && !empty($val['en_crumb'])) {
          $n++;
        }
      }
      $curr = $n;
    }
    if ($n > 0) {
      $form_state->set('num_crumbs', $n);
    }

    $form['#tree'] = TRUE;
    $form['pre_breadcrumbs'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Breadcrumbs for this website'),
      '#prefix' => '<div id="crumbs-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    if (empty($curr)) {
      $form_state->set('num_crumbs', 1);
    }

    for ($i = 0; $i < $curr; $i++) {
      $form['pre_breadcrumbs'][$i]['en_crumb'] = [
        '#type' => 'textarea',
        '#default_value' => $config->get('en')[$i]['en_crumb'] ?? '',
        '#title' => $this->t('English breadcrumb name @placeholder', ['@placeholder' => $i]),
      ];
      $form['pre_breadcrumbs'][$i]['en_url'] = [
        '#type' => 'textarea',
        '#default_value' => $config->get('en')[$i]['en_url'] ?? '',
        '#title' => $this->t('English breadcrumb URL or relative path @placeholder', ['@placeholder' => $i]),
      ];
      $form['pre_breadcrumbs'][$i]['fr_crumb'] = [
        '#type' => 'textarea',
        '#default_value' => $config->get('fr')[$i]['fr_crumb'] ?? '',
        '#title' => $this->t('French breadcrumb name @placeholder', ['@placeholder' => $i]),
      ];
      $form['pre_breadcrumbs'][$i]['fr_url'] = [
        '#type' => 'textarea',
        '#default_value' => $config->get('fr')[$i]['fr_url'] ?? '',
        '#title' => $this->t('French breadcrumb URL or relative path @placeholder', ['@placeholder' => $i]),
      ];
    }
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['pre_breadcrumbs']['actions']['add_crumb'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'crumbs-fieldset-wrapper',
      ],
    ];
    if ($curr > 1) {
      $form['pre_breadcrumbs']['actions']['remove_crumb'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove one'),
        '#submit' => ['::removeCallback'],
        '#ajax' => [
          'callback' => '::addmoreCallback',
          'wrapper' => 'crumbs-fieldset-wrapper',
        ],
      ];
    }
    $form_state->setCached(FALSE);
    return parent::buildForm($form, $form_state);
  }

  /**
   * Submit callback for the add one button.
   */
  public function addOne(array &$form, FormStateInterface &$form_state) {
    $curr = $form_state->get('num_crumbs');
    $form_state->set('num_crumbs', $curr + 1);
    $form_state->setRebuild();
  }

  /**
   * Callback function to add another breadcrumb group.
   */
  public function addmoreCallback(array &$form, FormStateInterface &$form_state) {
    $curr = $form_state->get('num_crumbs');
    return $form['pre_breadcrumbs'];
  }

  /**
   * Callback function to add remove a breadcrumb group.
   */
  public function removeCallback(array &$form, FormStateInterface &$form_state) {
    $curr = $form_state->get('num_crumbs');
    if ($curr > 1) {
      $form_state->set('num_crumbs', $curr - 1);
    }
    $form_state->setRebuild();
  }

  /**
   * Validate form, ensure https for crumb url enforce external to this site.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('pre_breadcrumbs');
    $values_en = [];
    $values_fr = [];
    $cnt = 0;
    foreach ($values as $fieldset_key => $fieldset_values) {
      if (isset($fieldset_values['en_crumb'])) {
        $values_en[$cnt]['en_crumb'] = $fieldset_values['en_crumb'];
      }
      if (isset($fieldset_values['en_url'])) {
        $values_en[$cnt]['en_url'] = $fieldset_values['en_url'];
      }
      if (isset($fieldset_values['fr_crumb'])) {
        $values_fr[$cnt]['fr_crumb'] = $fieldset_values['fr_crumb'];
      }
      if (isset($fieldset_values['fr_url'])) {
        $values_fr[$cnt]['fr_url'] = $fieldset_values['fr_url'];
      }
      if (isset($fieldset_values['en_url'])) {
        $urlVal = $this->getUrlByKey($values_en, $cnt);
        if (strlen($urlVal) < 11 || stripos($urlVal, 'http') < 0) {
          $form_state->setErrorByName('en_url', $this->t('Please provide a valid external url.'));
          // @todo improve this with the api validate urls
          // https://api.drupal.org/api/drupal/vendor%21symfony%21validator%21Constraints%21UrlValidator.php/class/UrlValidator/9.0.x
        }
      }
      if (isset($fieldset_values['fr_url'])) {
        $urlVal = $this->getUrlByKey($values_en, $cnt);
        if (strlen($urlVal) < 11 || stripos($urlVal, 'http') < 0) {
          $form_state->setErrorByName('fr_url', $this->t('Please provide a valid external url.'));
          // @todo improve this with the api validate urls
          // https://api.drupal.org/api/drupal/vendor%21symfony%21validator%21Constraints%21UrlValidator.php/class/UrlValidator/9.0.x
        }
      }
      if (isset($fieldset_values['en_crumb'])) {
        $urlVal = $this->getUrlByKey($values_en, $cnt);
        if (strlen($urlVal) < 2) {
          $form_state->setErrorByName('en_crumb', $this->t('Please provide a valid breadcrumb name.'));
          // @todo improve this with the api validate urls
          // https://api.drupal.org/api/drupal/vendor%21symfony%21validator%21Constraints%21UrlValidator.php/class/UrlValidator/9.0.x
        }
      }
      if (isset($fieldset_values['fr_crumb'])) {
        $urlVal = $this->getUrlByKey($values_fr, $cnt);
        if (strlen($urlVal) < 2) {
          $form_state->setErrorByName('fr_crumb', $this->t('Please provide a valid breadcrumb name.'));
          // @todo improve this with the api validate urls
          // https://api.drupal.org/api/drupal/vendor%21symfony%21validator%21Constraints%21UrlValidator.php/class/UrlValidator/9.0.x
        }
      }

      $cnt++;
    }

  }

  /**
   * Submit handler store configuration for use in pre_breadcrumbs.module.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('pre_breadcrumbs');
    $config = $this->configFactory->getEditable('pre_breadcrumbs.settings');
    $values_en = [];
    $values_fr = [];
    $cnt = 0;
    foreach ($values as $fieldset_key => $fieldset_values) {
      if (isset($fieldset_values['en_crumb'])) {
        $values_en[$cnt]['en_crumb'] = $fieldset_values['en_crumb'];
      }
      if (isset($fieldset_values['en_url'])) {
        $values_en[$cnt]['en_url'] = $fieldset_values['en_url'];
      }
      if (isset($fieldset_values['fr_crumb'])) {
        $values_fr[$cnt]['fr_crumb'] = $fieldset_values['fr_crumb'];
      }
      if (isset($fieldset_values['fr_url'])) {
        $values_fr[$cnt]['fr_url'] = $fieldset_values['fr_url'];
      }
      $cnt++;
    }

    $config->set('en', $values_en);
    $config->set('fr', $values_fr);
    $config->set('front_page_crumb', $form_state->getValue('front_page_crumb'));
    $config->save();

    parent::submitForm($form, $form_state);

    // Print output message with configuration changes.
    $breadcrumbStringEn = '';
    $cnt = count($values_en);
    $innerCnt = 0;
    foreach ($values_en as $key => $v) {
      $innerCnt++;
      foreach ($v as $innerV) {
        if ($cnt == $innerCnt) {
          $breadcrumbStringEn .= ' ' . $innerV;
        }
        else {
          $breadcrumbStringEn .= $innerV . ', ';
        }
      }
    }
    $breadcrumbStringFr = '';
    $cnt = count($values_fr);
    $innerCnt = 0;
    foreach ($values_en as $key => $v) {
      $innerCnt++;
      foreach ($v as $innerV) {
        if ($cnt == $innerCnt) {
          $breadcrumbStringFr .= ' ' . $innerV;
        }
        else {
          $breadcrumbStringFr .= $innerV . ', ';
        }
      }
    }
    $output = t('These breadcrumbs are going to be added: @crumbs', [
      '@crumbs' => $breadcrumbStringEn . ' and french breadcrumbs: '
      . $breadcrumbStringFr,
    ]
    );
    $this->messenger()->addMessage($output);
  }

  /**
   * Helper function for form validation of URLs.
   */
  private function getUrlByKey($values, $level = 0) {
    $cnt = count($values);
    $innerCnt = 0;
    foreach ($values as $key => $v) {
      $innerCnt++;
      if ($values[$key] == $values[$level]) {
        foreach ($v as $cle => $innerV) {
          if ($cle == 'en_url') {
            return $innerV;
          }
          if ($cle == 'fr_url') {
            return $innerV;
          }
        }
      }
    }
  }

}
