<?php /** @noinspection ALL */

namespace Drupal\ebms_article\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ebms_article\Search;
use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_core\Entity\SavedRequest;
use Drupal\ebms_core\TermLookup;
use Drupal\ebms_import\Entity\Batch;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form used to search for articles.
 *
 * @ingroup ebms
 */
class SearchForm extends FormBase {

  /**
   * Connection to the database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $db;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The `Search` service.
   *
   * @var \Drupal\ebms_article\Search
   */
  protected Search $searchService;

  /**
   * The term_lookup service.
   *
   * @var \Drupal\ebms_core\TermLookup
   */
  protected TermLookup $termLookup;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->termLookup = $container->get('ebms_core.term_lookup');
    $instance->db = $container->get('database');
    $instance->searchService = $container->get('ebms_article.search');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ebms_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $search_id = NULL): array {

    // See if we are running a restricted or full version of search.
    $user = User::load($this->currentUser()->id());
    $restricted = !$user->hasPermission('perform full search');

    // Set default values, possibly using values from a previous search.
    $params = empty($search_id) ? [] : SavedRequest::loadParameters($search_id);
    $selected_boards = $params['board'] ?? $form_state->getValue('board') ?? [];
    $topic = $params['topic'] ?? [];
    $topic_logic = $params['topic-logic'] ?? 'or';
    $pmid = $params['pmid'] ?? $this->getRequest()->get('pmid');
    $authors = $params['authors'] ?? '';
    $author_position = $params['author-position'] ?? 'first';
    $title = $params['title'] ?? '';
    $journal = $params['journal'] ?? '';
    $publication_year = $params['publication-year'] ?? '';
    $publication_month = $params['publication-month'] ?? '';
    $sort = $params['sort'] ?? $user->search_sort->value ?? ($restricted ? 'pmid' : 'ebms-id');
    $per_page_options = [
      '10' => '10',
      '25' => '25',
      '50' => '50',
      'all' => 'View All',
    ];
    $per_page = $params['per-page'] ?? $user->search_per_page->value ?? 10;
    if (!array_key_exists($per_page, $per_page_options)) {
      $per_page = 10;
    }

    // We only need these for the full version of the search page.
    if (!$restricted) {
      $ebms_id = $params['ebms_id'] ?? '';
      $article_tag = $params['article-tag'] ?? 0;
      $tag_start = $params['tag-start'] ?? '';
      $tag_end = $params['tag-end'] ?? '';
      $reviewer = $params['reviewer'] ?? 0;
      $disposition = $params['disposition'] ?? 0;
      $meeting_category = $params['meeting-category'] ?? 0;
      $meeting_start = $params['meeting-start'] ?? '';
      $meeting_end = $params['meeting-end'] ?? '';
      $decision = $params['decision'] ?? 0;
      $cycle = $params['cycle'] ?? '';
      $cycle_start = $params['cycle-start'] ?? '';
      $cycle_end = $params['cycle-end'] ?? '';
      $fyi = $params['fyi'] ?? '';
      $abstract_decision = $params['abstract-decision'] ?? '';
      $full_text = $params['full-text'] ?? '';
      $full_text_decision = $params['full-text-decision'] ?? '';
      $core_journals = $params['core-journals'] ?? '';
      $comment = $params['comment'] ?? '';
      $comment_start = $params['comment-start'] ?? '';
      $comment_end = $params['comment-end'] ?? '';
      $board_manager_comment = $params['board-manager-comment'] ?? '';
      $import_start = $params['import-start'] ?? '';
      $import_end = $params['import-end'] ?? '';
      $modified_start = $params['modified-start'] ?? '';
      $modified_end = $params['modified-end'] ?? '';
      if (!empty($params['filters'])) {
        $filters = [];
        foreach ($params['filters'] as $key => $value) {
          if (!empty($value)) {
            $filters[] = $key;
          }
        }
      }
      else {
        $filters = ['unpublished', 'not-listed', 'rejected'];
      }
    }

    // Create the picklists we need for all searches.
    $boards = Board::boards();
    $topics = ['' => 'Select a board'];
    if (!empty($selected_boards)) {
      $topics = $this->getTopics($selected_boards);
    }
    if (!empty($topic)) {
      foreach ($topic as $topic_id) {
        if (!array_key_exists($topic_id, $topics)) {
          $topic = [];
          break;
        }
      }
    }
    $now = new \DateTime();
    $pub_years = [];
    for ($year = (int) $now->format('Y'); $year >= 1867; $year--) {
      $pub_years[$year] = $year;
    }
    $sort_options = [
      'pmid' => 'PubMed ID',
      'title' => 'Title',
      'author' => 'Author',
      'journal' => 'Journal',
    ];

    // Assemble picklists only needed for the full search page.
    if (!$restricted) {

      // Picklist for review cycles.
      $cycles = Batch::cycles();

      // Access to valid-value term lists.
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');

      // Values board members can assign in review of assigned packets.
      $dispositions = [];
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('vid', 'dispositions');
      $query->sort('weight');
      $entities = $storage->loadMultiple($query->execute());
      foreach ($entities as $entity) {
        $dispositions[$entity->id()] = $entity->getName();
      }

      // For searches based on meetings in which the articles are discussed.
      $meeting_categories = [];
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('vid', 'meeting_categories');
      $query->sort('name');
      $entities = $storage->loadMultiple($query->execute());
      foreach ($entities as $entity) {
        $meeting_categories[$entity->id()] = $entity->getName();
      }

      // Final decisions the board can assign for an article.
      $board_decisions = [];
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('vid', 'board_decisions');
      $query->sort('name');
      $entities = $storage->loadMultiple($query->execute());
      foreach ($entities as $entity) {
        $board_decisions[$entity->id()] = $entity->getName();
      }

      // Tags which can be assigned to articles.
      $article_tags = [];
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('vid', 'article_tags');
      $query->sort('name');
      $entities = $storage->loadMultiple($query->execute());
      foreach ($entities as $entity) {
        $article_tags[$entity->id()] = $entity->getName();
      }

      // Reviewers who have been assigned packets for article review.
      // We understand that querying the database directly instead of using
      // entity queries is frowned on in the Drupal community. The primary
      // objection is that there is no documented guarantee that the database
      // table and column naming conventions will never change. However, the
      // entity query APIs are unacceptably inefficient for some tasks.
      // Creating this picklist is one of those tasks. Loading the 18,699
      // packet entities and extracting all the unique reviewer IDs takes a
      // little under two and a half minutes (extrapolating from the Drupal 9
      // prototype by scaling up from the number of packets the prototype has).
      // Takes under a third of a second doing it this way. The users will not
      // accept a search page which takes multiple minutes to load. Bear in
      // mind that creating this picklist is not the only thing this page has
      // to do. 😂
      // When Drupal makes its entity queries more capable and efficient,
      // perhaps this can be rewritten.
      $reviewers = [];
      $query = $this->db->select('ebms_packet__reviewers', 'r')
                    ->distinct()
                    ->fields('u', ['uid', 'name']);
      $query->join('users_field_data', 'u', 'u.uid = r.reviewers_target_id');
      $results = $query->execute();
      foreach ($results as $result) {
        $reviewers[$result->uid] = $result->name;
      }

      // Unrestricted searches have more options for sorting.
      $sort_options = [
        'ebms-id' => 'EBMS ID',
        'pmid' => 'PubMed ID',
        'title' => 'Title',
        'author' => 'Author',
        'journal' => 'Journal',
        'core' => 'Core Journals',
      ];
    }

    $form = [
      '#attached' => [
        'library' => ['ebms_article/search-form'],
      ],
      'restricted' => [
        '#type' => 'hidden',
        '#value' => $restricted,
      ],
      'basic' => [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $restricted ? 'Filtering Options' : 'Basic Search',
        'board' => [
          '#type' => 'select',
          '#title' => 'Board',
          '#options' => $boards,
          '#default_value' => $selected_boards,
          '#multiple' => TRUE,
          '#ajax' => [
            'callback' => '::getTopicsCallback',
            'wrapper' => 'board-controlled',
            'event' => 'change',
          ],
        ],
        'board-controlled' => [
          '#type' => 'container',
          '#attributes' => ['id' => 'board-controlled'],
          'topic' => [
            '#type' => 'select',
            '#title' => 'Topic',
            '#options' => $topics,
            '#default_value' => $topic,
            '#multiple' => TRUE,
            '#validated' => TRUE,
          ],
          'topic-logic' => [
            '#type' => 'radios',
            '#title' => 'Topic Search Method',
            '#options' => [
              'and' => 'Find articles with ALL of the selected topics.',
              'or' => 'Find articles with ANY of the selected topics.',
            ],
            '#default_value' => $topic_logic,
            '#description' => 'Logic for searching by topics assigned to the articles.',
          ],
          /*
           * This is broken.
           * See https://www.drupal.org/project/drupal/issues/1149078.
           * '#states' => [
           *   'visible' => [
           *     ':input[name="board[]"]' => ['selected' => '!empty'],
           *   ],
           * ],
           * End of broken fragment.
           */
        ],
      ],
    ];

    // Only the full search users can search by our internal IDs.
    if ($restricted) {
      $form['basic']['pmid'] = [
        '#type' => 'textfield',
        '#title' => 'PubMed ID',
        '#default_value' => $pmid,
      ];
    }
    else {
      $form['basic']['article-id-row'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['grid-row', 'grid-gap']],
        'pmid-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'pmid' => [
            '#type' => 'textfield',
            '#title' => 'PubMed ID',
            '#default_value' => $pmid,
          ],
        ],
        'ebms-id-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'ebms_id' => [
            '#type' => 'textfield',
            '#title' => 'EBMS ID',
            '#default_value' => $ebms_id,
          ],
        ],
      ];
    }
    $form['basic']['authors'] = [
      '#type' => 'textfield',
      '#title' => 'Author',
      '#description' => 'Separate multiple author names (surname and initials) with semicolons, using wildcards for partial matches (e.g., Fisher CL; Morris-Rose%; %smith%).',
      '#default_value' => $authors,
    ];
    $form['basic']['author-position'] = [
      '#type' => 'radios',
      '#title' => 'Author Position',
      '#description' => 'First and Last will only yield results when a single author is named.',
      '#options' => [
        'first' => 'First',
        'last' => 'Last',
        'any' => 'Any',
      ],
      '#default_value' => $author_position,
    ];
    $form['basic']['title'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#description' => 'Use wildcards (for example, %european organi%ation for research%) to find partial matches.',
      '#default_value' => $title,
    ];
    $form['basic']['journal'] = [
      '#type' => 'textfield',
      '#title' => 'Journal Title',
      '#description' => 'Search by full journal title. Wildcards can be used for partial matches (for example, %surgical oncology%).',
      '#default_value' => $journal,
    ];
    $form['basic']['publication-date'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
      '#title' => 'Publication Date',
      'publication-year' => [
        '#type' => 'select',
        '#title' => 'Publication Year',
        '#options' => $pub_years,
        '#default_value' => $publication_year,
        '#empty_value' => '',
      ],
      'publication-month' => [
        '#type' => 'select',
        '#title' => 'Month',
        '#options' => [
          1 => 'January',
          2 => 'February',
          3 => 'March',
          4 => 'April',
          5 => 'May',
          6 => 'June',
          7 => 'July',
          8 => 'August',
          9 => 'September',
          10 => 'October',
          11 => 'November',
          12 => 'December',
        ],
        '#default_value' => $publication_month,
        '#empty_value' => '',
      ],
    ];

    // Advanced and administrator search are only for full searches.
    if (!$restricted) {
      $form['advanced'] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => 'Advanced Search',
        'tag-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-row', 'grid-gap']],
          'article-tag-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'article-tag' => [
              '#type' => 'select',
              '#title' => 'Tag',
              '#options' => $article_tags,
              '#default_value' => $article_tag,
              '#empty_value' => '',
            ],
          ],
          'tag-date-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'tag-date' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['inline-fields']],
              '#title' => 'Tag Date Range',
              'tag-start' => [
                '#type' => 'date',
                '#default_value' => $tag_start,
              ],
              'tag-end' => [
                '#type' => 'date',
                '#default_value' => $tag_end,
              ],
            ],
          ],
        ],
        'reviewer-and-response-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-row', 'grid-gap']],
          'reviewer-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'reviewer' => [
              '#type' => 'select',
              '#title' => 'Reviewer',
              '#options' => $reviewers,
              '#default_value' => $reviewer,
              '#empty_value' => '',
            ],
          ],
          'reviewer-response-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'disposition' => [
              '#type' => 'select',
              '#title' => 'Reviewer Response',
              '#options' => $dispositions,
              '#default_value' => $disposition,
              '#empty_value' => '',
            ],
          ],
        ],
        'meeting-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-row', 'grid-gap']],
          'meeting-category-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'meeting-category' => [
              '#type' => 'select',
              '#title' => 'Meeting Category',
              '#options' => $meeting_categories,
              '#default_value' => $meeting_category,
              '#empty_value' => '',
            ],
          ],
          'meeting-date-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'meeting-date' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['inline-fields']],
              '#title' => 'Meeting Date Range',
              'meeting-start' => [
                '#type' => 'date',
                '#default_value' => $meeting_start,
              ],
              'meeting-end' => [
                '#type' => 'date',
                '#default_value' => $meeting_end,
              ],
            ],
          ],
        ],
        'decision' => [
          '#type' => 'select',
          '#title' => 'Board Decision',
          '#options' => $board_decisions,
          '#default_value' => $decision,
          '#empty_value' => '',
        ],
        'cycle-and-date-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-row', 'grid-gap']],
          'cycle-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'cycle' => [
              '#type' => 'select',
              '#title' => 'Review Cycle',
              '#options' => $cycles,
              '#description' => 'Find articles assigned to at least one topic for this review cycle.',
              '#default_value' => $cycle,
              '#empty_value' => '',
            ],
            ],
          'cycle-range-wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
            'cycle-range' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['inline-fields']],
              '#title' => 'Review Cycle Range',
              'cycle-start' => [
                '#type' => 'select',
                '#options' => $cycles,
                '#default_value' => $cycle_start,
                '#empty_value' => '',
              ],
              'cycle-end' => [
                '#type' => 'select',
                '#options' => $cycles,
                '#default_value' => $cycle_end,
                '#empty_value' => '',
              ],
            ],
          ],
        ],
        'fyi' => [
          '#type' => 'radios',
          '#title' => 'Only find articles which are marked as FYI.',
          '#options' => [
            'yes' => 'Yes',
            'no' => 'No',
          ],
          '#default_value' => $fyi,
        ],
        'abstract-decision' => [
          '#type' => 'radios',
          '#title' => 'Find articles with decisions from review of the abstracts.',
          '#options' => [
            'passed_bm_review' => 'Accepted',
            'reject_bm_review' => 'Rejected',
          ],
          '#default_value' => $abstract_decision,
        ],
        'full-text' => [
          '#type' => 'radios',
          '#title' => 'Find articles based on full text retrieved.',
          '#options' => [
            'yes' => 'Retrieved',
            'no' => 'Not Retrieved',
          ],
          '#default_value' => $full_text,
        ],
        'full-text-decision' => [
          '#type' => 'radios',
          '#title' => 'Find articles with decisions from review of the full text.',
          '#options' => [
            'passed_full_review' => 'Accepted',
            'reject_full_review' => 'Rejected',
          ],
          '#default_value' => $full_text_decision,
        ],
        'core-journals' => [
          '#type' => 'radios',
          '#title' => 'Find articles published in core journals.',
          '#options' => [
            'yes' => 'Yes',
            'no' => 'No',
          ],
          '#default_value' => $core_journals,
        ],
        'comment' => [
          '#type' => 'textfield',
          '#title' => 'Comment',
          '#description' => 'Find articles matching this comment on one of their states (wildcards supported).',
          '#default_value' => $comment,
        ],
        'comment-date' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['inline-fields']],
          '#title' => 'Comment Date Range',
          'comment-start' => [
            '#type' => 'date',
            '#default_value' => $comment_start,
          ],
          'comment-end' => [
            '#type' => 'date',
            '#default_value' => $comment_end,
          ],
        ],
        'board-manager-comment' => [
          '#type' => 'textfield',
          '#title' => 'Board Manager Comment',
          '#description' => 'Find articles matching this comment in a topic assignment (wildcards supported).',
          '#default_value' => $board_manager_comment,
        ],
      ];
      $form['admin'] = [
        '#type' => 'details',
        '#title' => 'Administrator Search',
        'import-date' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['inline-fields']],
          '#title' => 'Import Date Range',
          'import-start' => [
            '#type' => 'date',
            '#default_value' => $import_start,
          ],
          'import-end' => [
            '#type' => 'date',
            '#default_value' => $import_end,
          ],
        ],
        'modified-date' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['inline-fields']],
          '#title' => 'Modification Date Range',
          '#description' => 'Find articles which changed state during the specified date range',
          'modified-start' => [
            '#type' => 'date',
            '#default_value' => $modified_start,
          ],
          'modified-end' => [
            '#type' => 'date',
            '#default_value' => $modified_end,
          ],
        ],
        'filters' => [
          '#type' => 'checkboxes',
          '#title' => 'Filters',
          '#options' => [
            'unpublished' => 'Include unpublished articles',
            'only-unpublished' => 'Include only unpublished articles',
            'not-listed' => 'Include "not-listed" articles',
            'only-not-listed' => 'Include only "not-listed" articles',
            'rejected' => 'Include rejected articles',
            'only-rejected' => 'Include only rejected articles',
            'topics-added' => 'Include only articles with additional topics assigned',
          ],
          '#default_value' => $filters,
        ],
      ];
    }
    else {
      // The restricted version of searching only finds articles with PDFs.
      $form['full-text'] = [
        '#type' => 'hidden',
        '#value' => 'yes',
      ];
    }
    if (!array_key_exists($sort, $sort_options)) {
      $sort = $restricted ? 'pmid' : 'ebms-id';
    }
    $form['display-options'] = [
      '#type' => 'details',
      '#title' => 'Display Options',
      'sort' => [
        '#type' => 'radios',
        '#title' => 'Sort By',
        '#required' => TRUE,
        '#options' => $sort_options,
        '#default_value' => $sort,
      ],
      'per-page' => [
        '#type' => 'radios',
        '#title' => 'Per Page',
        '#required' => TRUE,
        '#options' => $per_page_options,
        '#default_value' => $per_page,
      ],
    ];

    // Restricted search users are not allowed to persist display choices.
    // @todo find out if this distinction is still desired.
    if (!$restricted) {
      $form['display-options']['persist'] = [
        '#type' => 'checkbox',
        '#title' => 'Save display options',
        '#description' => 'Check this box if you wish the selected options to be used as the defaults for subsequent searches.',
      ];
      $form['display-options']['board-member-version'] = [
        '#type' => 'checkbox',
        '#title' => 'Board member version',
        '#description' => 'Check this box if you want a simplified version of the report which can be pasted into a word-processing document for distribution to board members.',
      ];
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit',
    ];
    $form['reset'] = [
      '#type' => 'submit',
      '#value' => 'Reset',
      '#submit' => ['::resetSubmit'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * Get the topics for the selected boards.
   *
   * @param array $selected_boards
   *   IDs for the board the user has selected.
   *
   * @return array
   *   Topic names indexed by topic IDs.
   */
  private function getTopics(array $selected_boards): array {
    return empty($selected_boards) ? [0 => 'Select a board'] : Topic::topics($selected_boards);
  }

  /**
   * Fill in the portion of the form driven by board selection.
   *
   * @param array $form
   *   Render array we are adjusting.
   * @param FormStateInterface $form_state
   *   Access to the form's values.
   */
  public function getTopicsCallback(array &$form, FormStateInterface $form_state) {
    $selected_boards = $form_state->getValue('board');
    $options = $this->getTopics($selected_boards);
    $form['basic']['board-controlled']['topic']['#options'] = $options;
    return $form['basic']['board-controlled'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Save the search parameters.
    $parameters = $form_state->getValues();
    if (!empty($parameters['persist'])) {
      $storage = $this->entityTypeManager->getStorage('user');
      $user = $storage->load($this->currentUser()->id());
      $user->set('search_per_page', $parameters['per-page'] ?? 10);
      $user->set('search_sort', $parameters['sort'] ?? 'ebms-id');
      $user->save();
    }
    $search_request = SavedRequest::saveParameters('article search', $parameters);

    // Navigate to the report.
    $route = empty($parameters['board-member-version']) ? 'ebms_article.search_results' : 'ebms_article.simple_search_results';
    $parameters = ['request_id' => $search_request->id()];
    $form_state->setRedirect($route, $parameters);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement()['#value'];
    if ($trigger === 'Reset') {
      $route = 'ebms_article.search_form';
      $form_state->setRedirect($route);
    }
    else if ($trigger === 'Submit') {
      parent::validateForm($form, $form_state);
      $parameters = $form_state->getValues();
      $boards = [];
      if (!empty($parameters['board'])) {
        foreach ($parameters['board'] as $key => $value) {
          if (!empty($value)) {
            $boards[] = $key;
          }
        }
      }
      $form_state->setValue('boards', $boards);
      $topics = [];
      if (!empty($parameters['topic'])) {
        foreach ($parameters['topic'] as $key => $value) {
          if (!empty($value)) {
            $topics[] = $key;
          }
        }
      }
      $form_state->setValue('topics', $topics);
    }
  }

  /**
   * Create a fresh search form with default values.
   *
   * @param array $form
   *   Form settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form values.
   */
  public function resetSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('ebms_article.search_form');
  }

}
