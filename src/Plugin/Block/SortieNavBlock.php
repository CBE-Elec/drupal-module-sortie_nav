<?php
/**
 * @file
 * Contains \Drupal\sortie_nav\Plugin\Block\SortieNavBlock.
 */
namespace Drupal\sortie_nav\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Database;
use Drupal\Component\Utility\Html;

/**
 * Block barre de navigation entre les sorties de l'Aquarius.
 *
 * @Block(
 *   id = "sortie_nav_block",
 *   admin_label = @Translation("Barre de navigation sorties"),
 * )
 */
class SortieNavBlock extends BlockBase {
  private $sortie_type; // Restriction à certains types seulement (NULL pour les avoir toutes); récupéré en paramètre optionnel 'type' de l'url, par exemple /sortie?type=lac+fosse
  private $sortie_lst;  // Liste des sortie, récupérée en BdD

  /**
   * Construction des variables pour composer le bloc. Point d'entrée principal: renvoie le contenu d'une page du type '/sortie/12?type=mer'.
   * 
   * @return array avec le thème et les données à utiliser.
   */
  public function build() {
    // 1. Traitement des paramètres reçus depuis l'url: par exemple /sortie/12?type=mer
    // 1.1. Identifiant de la sortie: dernier argument de l'url (hors arguments get); on le sanitise par sécurité
	$path = \Drupal::service('path.current')->getPath();
	$path_array = explode("/", $path);
	$path_end = end($path_array);
    $sortie_id = Html::escape($path_end);
    if (! ctype_digit(strval($sortie_id))) {	// Si $sortie_id n'est pas un nombre entier...
      $sortie_id = NULL;
    }
    //kint($sortie_id);
    
    // 1.2. Type de la sortie: argument optionnel get; on le sanitise par sécurité
    $sortie_type = Html::escape(\Drupal::request()->get('type'));
    if ($sortie_type == 'all') {	// Valeur NULL pour avoir tous les types
      $sortie_type = NULL;
    }
    $this->sortie_type = $sortie_type;
    //kint($sortie_type);

    // 2. Validation de la sortie reçue, à défaut récupération de la prochaine
    if (! $this->exists_id($sortie_id)) {
      // Première sortie suivant la date courante
      $sortie_id = $this->get_id_suiv(date('Y-m-d H:i:s'));
    }

    // 3. Dates bornant les sorties à afficher
    $sortie_date = $this->get_date_fin($sortie_id);
    $date_prem = date('Y-m-d H:i:s', strtotime('-12 month', strtotime($sortie_date)));
    $date_dern = date('Y-m-d H:i:s', strtotime('+12 month', strtotime($sortie_date)));
    //kint($sortie_date); kint($date_prem); kint($date_dern);
    
    // 4. Composition de la navbar
    $prec_lst = array();
    $suiv_lst = array();
    $courant = array();
    foreach ($entries = $this->load() as $entry) {
      if ($date_prem <= $entry['field_dates_end_value'] && $entry['field_dates_end_value'] < $sortie_date) {
        // Ajout de l'élément au début du tableau
        array_unshift($prec_lst, array('id' => $entry['id'], 'nom' => $entry['label'], 'type' => $entry['name']));
      }
      if ($entry['id'] == $sortie_id) {
        // Sortie visée
        $courant = array('id' => $entry['id'], 'nom' => $entry['label'], 'type' => $entry['name']);
      }
      if ($sortie_date < $entry['field_dates_end_value'] && $entry['field_dates_end_value'] <= $date_dern) {
        // Ajout de l'élément à la fin du tableau
        $suiv_lst[] = array('id' => $entry['id'], 'nom' => $entry['label'], 'type' => $entry['name']);
      }
    }
    $prec = reset($prec_lst); // Reprise de l'élément le plus proche avant
    $suiv = reset($suiv_lst); // Reprise de l'élément le plus proche après
    //kint($this->report()); // Débug: sortie du tableau des sorties récupérées
    
    // 5. Retour pour affichage: renvoi du thème d'affichage et des variables à afficher
    return array(
      '#theme' => 'sortie_navbar',
      '#sortie_type' => str_replace(" ", "+", $sortie_type),
      '#prec_lst' => $prec_lst,
      '#prec' => $prec,
      '#courant' => $courant,
      '#suiv' => $suiv,
      '#suiv_lst' => $suiv_lst,
//      '#attached' => array(	// Finalement, j'ai plutôt appelé la librairie de theming (pointant vers la css) à partir du template sortie-navbar.html.twig
//        'library' => array(
//          'sortie_nav/sortie-navbar',
//        ),
//      ),
      '#cache' => array('max-age' => 0,),	// Désactiver le cache, sinon le bloc n'est pas raffraîchi lors de la nvigation !
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   */
  /*public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();
    return $form;
  }*/

  /**
   * {@inheritdoc}
   */
  /*public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['sortie_nav_settings'] = $form_state->getValue('sortie_nav_settings');
  }*/

  /**
   * Récupérer la liste des sorties (de type $this->sortie_type).
   * 
   * @return array
   */
  protected function load() {
    if ($this->sortie_lst == NULL) {
      // Requête en BdD
      $select = Database::getConnection()->select('groups_field_data', 'g');
      $select->innerJoin('group__field_type_de_sortie ', 's', 'g.id = s.entity_id');
      $select->innerJoin('taxonomy_term_field_data ', 't', 's.field_type_de_sortie_target_id = t.tid');
      $select->leftJoin('group__field_dates', 'd', 'g.id = d.entity_id');
      $select->condition('g.type', 'sortie');
      $select->condition('d.deleted', '0');
      if ($this->sortie_type) {
        $type = explode(' ', $this->sortie_type); // /sortie?type=lac+fosse va donner $type=['lac', 'fosse'] qui sera interprétée comme une condition OU
        $select->condition('t.name', $type, 'IN');
      }
      $select->fields('g', array('id', 'label'));
      $select->fields('t', array('name'));
      $select->fields('d', array('field_dates_end_value'));
      $select->orderBy('d.field_dates_end_value', 'ASC');
      $entries = $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
      $this->sortie_lst = $entries;
    } else {
      // On l'avait déjà
      $entries = $this->sortie_lst;
    }
    //kint($entries);
    return $entries;
  }

  /**
   * Créer la page de rapport: très utile en débug.
   * 
   * @return array
   * Tableau de rendu pour le rapport.
   */
  public function report() {
    $content = array();
    $content['message'] = array(
      '#markup' => $this->t('Liste des sorties:'),
    );
    $headers = array(
      //t('Type de groupe'), // type
      t('Id'),
      t('Label'),
      t('Type de sortie'),
      t('Date de fin'),
    );
    $rows = array();
    foreach ($entries = $this->load() as $entry) {
      // Sanitisation de chaque entry
      $rows[] = array_map('Drupal\Component\Utility\SafeMarkup::checkPlain', $entry);
    }
    $content['table'] = array(
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => t('Pas de sorties.'),
    );
    // Pas de cache sur cette page
    //$content['#cache']['max-age'] = 0; //kint($content);
    return $content;
  }

  /**
   * Teste si l'id de sortie fait partie des sorties connues.
   * 
   * @return bool.
   */
  private function exists_id($sortie_id) {
    foreach ($entries = $this->load() as $entry) {
      if ($sortie_id == $entry['id']) {
        //kint(['exists_id('.$sortie_id.')=true']);
        return true;
      }
    }
    //kint(['exists_id('.$sortie_id.')=false']);
    return false;
  }

  /**
   * Date de fin de la sortie.
   * 
   * @return date ou FALSE si la sortie est inconnue.
   */
  private function get_date_fin($sortie_id) {
    foreach ($entries = $this->load() as $entry) {
      if ($sortie_id == $entry['id']) {
        //kint(['get_date_fin('.$sortie_id.')='.$entry['field_dates_end_value']]);
        return $entry['field_dates_end_value'];
      }
    }
    //kint(['get_date_fin('.$sortie_id.')=false']);
    return false;
  }

  /**
   * Id de la sortie juste postérieure à $date (la suivante).
   * 
   * @return id groupe de type sortie.
   */
  private function get_id_suiv($date) {
    $entries = $this->load();
    end($entries);
    $id_suiv = current($entries)['id'];     // Initialisation à la dernière du tableau (la plus tardive)
    while (prev($entries) !== FALSE) {      // Parcours du tableau à rebours
      if (current($entries)['field_dates_end_value'] >= $date) {
        $id_suiv = current($entries)['id']; // On récupère la plus récente postérieure à $date
      }
    }
    return $id_suiv;
  }

  /**
   * Id de la sortie juste antérieure à $date (la précédente).
   * 
   * @return id groupe de type sortie.
   */
  private function get_id_prec($date) {
    $entries = $this->load();
    reset($entries);
    $id_prec = current($entries)['id'];   // Initialisation à la première du tableau (sortie déroulée le plus tôt)
    foreach ($entries as $entry) {        // Parcours du tableau dans l'ordre du plus tôt au plus tard
      if ($entry['field_dates_end_value'] <= $date) {
        $id_prec = current($entry)['id']; // On récupère la plus tardive antérieure à $date
      }
    }
    return $id_prec;
  }

}
