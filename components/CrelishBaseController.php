<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:17
 */

namespace giantbits\crelish\components;

//use app\assets\AppAsset;
use yii;
use yii\base\Controller;

class CrelishBaseController extends Controller
{

  public $title;
  const SORT_ASC = 0;
  const SORT_DESC = 1;
  const SORT_NONE = 2;

  protected $locked = FALSE;
  protected $plugins;
  protected $requestUrl;
  protected $requestFile;
  protected $rawContent;
  protected $meta;
  protected $out;
  protected $pages;
  public $pageCollection;
  public $assetPath;
  protected $page;
  protected $previousPage;
  protected $nextPage;
  protected $template;

  protected $fileHandler;
  protected $configHandler;

  public function init()
  {
    Yii::$app->language = Yii::$app->request->get('language');

    $this->configHandler = new CrelishConfig();
    $this->configHandler->loadConfig();

    $this->fileHandler = new CrelishFileHandler();
    $this->fileHandler->setConfig($this->configHandler);

    /* Workflow.

    1) Build page collection.
      - used for routing (specially explicit ordering [01., 02., etc])
      - used for navigation building (on page menus etc)
    */
    $this->pageCollection = $this->fileHandler->buildPageCollection();
    $this->resolvePathRequested();

    parent::init();

  }

  private function resolvePathRequested()
  {
    $page = null;
    $this->requestUrl = Yii::$app->request->getPathInfo();

    if (!empty($this->requestUrl)) {

      $keys = explode('/', $this->requestUrl);

      foreach ($keys as $key) {

        $key = str_replace(".html", "", $key);

        if (empty($page) && key_exists($key, $this->pageCollection)) {
          $page = $this->pageCollection[$key];
        }
        if (isset($page[$key])) {
          $page = $page[$key];
        } else {
          $page = $page;
        }
      }
    }

    $this->page = $page;
  }

  public function actionRun()
  {
    $this->layout = 'main';

    //$this->dirtyHelper();

    if ($this->page) {
      $this->requestFile = $this->page['pathOrig'];
    } else {
      throw new \yii\web\NotFoundHttpException();
    }

    $this->rawContent = $this->fileHandler->loadFileContent($this->requestFile);

    $headers = $this->getMetaHeaders();
    $this->meta = $this->fileHandler->parseFileMeta($this->rawContent, $headers, $this->requestFile);

    // Build render output.
    $this->buildRenderOutput();

    // Switch layout if defined.
    if (!empty($this->meta['layout'])) {
      $this->layout = $this->meta['layout'];
    }

    // Switch template.
    $this->template = $this->fileHandler->selectTemplate($this->requestUrl, $this->meta);
    $this->title = $this->meta['title'];
    $this->view->title = $this->configHandler->config['site_title'] . ' ' . $this->title;

    $contentArray = explode("===", $this->out);

    if (count($contentArray) == 1) {
      $contentArray[1] = $contentArray[0];
      $contentArray[0] = '';
    }

    $pageData = array_merge([
      'summary' => $contentArray[0],
      'content' => $contentArray[1]
    ], $this->meta);

    //$assetBundle = AppAsset::register($this->view);
    \Yii::$app->params['assetPath'] = \Yii::$app->assetManager->getBundle('app\assets\AppAsset', true)->baseUrl;

    // Render template.
    return $this->render($this->template, [
      'page' => $pageData
    ]);
  }

  protected function buildRenderOutput()
  {

    $type = (!empty($this->meta['type'])) ? $this->meta['type'] : NULL;

    //Check for processor class.
    //Run processor.
    //Run default processor.
    $processorClass = 'crelish\plugin\core\\' . ucfirst($type) . 'TypeProcessor';

    if (class_exists($processorClass)) {

      $processor = new $processorClass($this->requestUrl, $this->requestFile, $this->meta, $this->rawContent, $this->fileHandler, $this->configHandler);
      $processor->fileHandler = $this->fileHandler;
      $processor->configHandler = $this->configHandler;
      $processedContent = $processor->getProcessorOutput();
      $this->out = $this->fileHandler->prepareFileContent($processedContent, $this->meta);

    } else {
      $this->out = $this->fileHandler->prepareFileContent($this->rawContent, $this->meta);
      $this->out = $this->fileHandler->parseFileContent($this->out);
    }
  }

  protected function readPages()
  {
    $this->pages = array();
    $files = $this->getFiles(Yii::$app->basePath . '/' . $this->configHandler->getConfig('content_dir'), $this->configHandler->getConfig('content_ext'));

    foreach ($files as $i => $file) {
      // skip 404 page
      if (basename($file) == '404' . $this->configHandler->getConfig('content_ext')) {
        unset($files[$i]);
        continue;
      }

      $id = substr($file, strlen($this->configHandler->getConfig('content_dir')), -strlen($this->configHandler->getConfig('content_ext')));

      // drop inaccessible pages (e.g. drop "sub.md" if "sub/index.md" exists)
      $conflictFile = $this->configHandler->getConfig('content_dir') . $id . '/index' . $this->configHandler->getConfig('content_ext');
      if (in_array($conflictFile, $files, TRUE)) {
        continue;
      }

      $url = $this->getPageUrl($id);
      if ($file != $this->requestFile) {
        $rawContent = file_get_contents($file);
        $meta = $this->fileHandler->parseFileMeta($rawContent, $this->getMetaHeaders());
      } else {
        $rawContent = &$this->rawContent;
        $meta = &$this->meta;
      }

      // build page data
      // title, description, author and date are assumed to be pretty basic data
      // everything else is accessible through $page['meta']
      $page = array(
        'id' => $id,
        'url' => $url,
        'title' => &$meta['title'],
        'description' => &$meta['description'],
        'author' => &$meta['author'],
        'time' => &$meta['time'],
        'date' => &$meta['date'],
        'date_formatted' => &$meta['date_formatted'],
        'raw_content' => &$rawContent,
        'meta' => &$meta
      );

      if ($file == $this->requestFile) {
        $page['content'] = &$this->out;
      }

      unset($rawContent, $meta);
      $this->pages[$id] = $page;
    }
  }

  public function getPageUrl($page)
  {
    return $page;
  }

  protected function getFiles($directory, $fileExtension = '', $order = self::SORT_ASC)
  {
    $directory = rtrim($directory, '/');
    $result = array();

    // Scandir() reads files in alphabetical order
    $files = scandir($directory, $order);
    $fileExtensionLength = strlen($fileExtension);
    if ($files !== FALSE) {
      foreach ($files as $file) {
        // exclude hidden files/dirs starting with a .; this also excludes the special dirs . and ..
        // exclude files ending with a ~ (vim/nano backup) or # (emacs backup)
        if ((substr($file, 0, 1) === '.') || in_array(substr($file, -1), array(
            '~',
            '#'
          ))
        ) {
          continue;
        }

        if (is_dir($directory . '/' . $file)) {
          // get files recursively
          $result = array_merge($result, $this->getFiles($directory . '/' . $file, $fileExtension, $order));
        } elseif (empty($fileExtension) || (substr($file, -$fileExtensionLength) === $fileExtension)) {
          $result[] = $directory . '/' . $file;
        }
      }
    }

    return $result;
  }

  public function getRequestUrl()
  {
    return $this->requestUrl;
  }

  public function getRawContent()
  {
    return $this->rawContent;
  }

  public function getMetaHeaders()
  {
    $headers = array(
      'title' => 'Title',
      'description' => 'Description',
      'author' => 'Author',
      'date' => 'Date',
      'robots' => 'Robots',
      'template' => 'Template'
    );

    //$this->triggerEvent('onMetaHeaders', array(&$headers));
    return $headers;
  }

  public function getNav($level = 1)
  {
    $pages = [];

    if (empty(Yii::$app->controller->pageCollection)) {
      $pageCollection = [];
    } else {
      $pageCollection = Yii::$app->controller->pageCollection;
    }

    foreach ($pageCollection as $page) {
      if ($page['structureLevel'] > $level) {
        continue;
      }

      $pageData = Yii::$app->controller->fileHandler->loadFileContent($page['pathOrig']);
      $headers = Yii::$app->controller->getMetaHeaders();
      $pageData = array_merge_recursive($page, Yii::$app->controller->fileHandler->parseFileMeta($pageData, $headers, $page['pathOrig']));

      $pages[] = [
        'title' => (!empty($pageData['menu'])) ? $pageData['menu'] : $pageData['title'],
        'uri' => $pageData['self_url']
      ];
    }

    echo Yii::$app->view->render('nav.twig', ['pages' => $pages]);
  }

  public function afterAction($action, $result)
  {
    if (key_exists('cache_static', $action->controller->meta) && $action->controller->meta['cache_static'] == true) {
      $this->fileHandler->createStaticFile(\Yii::$app->request->getPathInfo(), $result);
    }

    return parent::afterAction($action, $result);
  }

  private function dirtyHelper()
  {

    $jsonData = '[
{
"title":"Monteur (m\/w)",
"date":"2015\/11\/9",
"pdf":"2120_gruber.pdf",
"country":"Österreich",
"company":" Bernd Gruber GmbH ",
"address":"AT-5724 Stuhlfelden ",
"link":"http:\/\/www.bernd-gruber.at\/service\/karriere\/",
"link_portrait":null,
"logo":"bernd_gruber_logo.png",
"portrait":null
},
{
"title":"Lackierer (m\/w)",
"date":"2015\/11\/9",
"pdf":"2119_gruber.pdf",
"country":"Österreich",
"company":" Bernd Gruber GmbH ",
"address":"AT-5724 Stuhlfelden ",
"link":"http:\/\/www.bernd-gruber.at\/service\/karriere\/",
"link_portrait":null,
"logo":"bernd_gruber_logo.png",
"portrait":null
},
{
"title":"Buchhalter (m\/w)",
"date":"2016\/1\/20",
"pdf":"1679_Feederle.pdf",
"country":"Deutschland",
"company":"Paul Feederle GmbH",
"address":"DE-Karlsruhe",
"link":"http:\/\/www.feederle.de\/",
"link_portrait":null,
"logo":"Feederle_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter Montage (m\/w)",
"date":"2016\/1\/20",
"pdf":"11633_erlacher.pdf",
"country":"Südtirol",
"company":"Erlacher  Innenausbau Kg d. Erlacher Thomas GmbH",
"address":"I-39040 Barbian \/ Waidbruck (BZ)",
"link":"http:\/\/www.erlacher.it\/de\/home",
"link_portrait":null,
"logo":"erlacher_logo_fhk.png",
"portrait":null
},
{
"title":"Glaser (m\/w)",
"date":"2016\/1\/20",
"pdf":"985_Feederle.pdf",
"country":"Deutschland",
"company":"Paul Feederle GmbH",
"address":"DE-Karlsruhe",
"link":"http:\/\/www.feederle.de\/",
"link_portrait":null,
"logo":"Feederle_logo_fhk.gif",
"portrait":null
},
{
"title":"Abrechnungstechniker",
"date":"2015\/11\/24",
"pdf":"2344_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"Mechaniker (m\/w)",
"date":"2015\/7\/26",
"pdf":"2041_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Mechaniker (m\/w) für Land- und Baumaschinen",
"date":"2015\/7\/26",
"pdf":"1404_rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Staplerfahrer (m\/w)",
"date":"2015\/7\/26",
"pdf":"2039_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Betriebselektriker (m\/w)",
"date":"2015\/7\/26",
"pdf":"2040_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Flaschner\/Klemptner (m\/w)",
"date":"2016\/1\/27",
"pdf":"2387_schlosser.pdf",
"country":"Deutschland",
"company":"Schlosser Holzbau GmbH",
"address":"DE-Jagstzell",
"link":"http:\/\/www.schlosser-projekt.de\/de\/das_unternehmen\/stellenangebote.html",
"link_portrait":null,
"logo":"SchlosserI_logo_fhk.gif",
"portrait":null
},
{
"title":"Technischer Zeichner (w\/m)",
"date":"2016\/1\/20",
"pdf":"2504_schweitzer.pdf",
"country":"Südtirol",
"company":"Schweitzer Project AG ",
"address":"I- 39025 Naturns \/ Naturino",
"link":"http:\/\/www.schweitzerproject.com\/de\/company\/philosophy",
"link_portrait":null,
"logo":"schweitzer_logo_fhk.png",
"portrait":null
},
{
"title":"Ladenbautechniker (m\/w)",
"date":"2016\/1\/20",
"pdf":"2505_schweitzer.pdf",
"country":"Südtirol",
"company":"Schweitzer Project AG ",
"address":"I- 39025 Naturns \/ Naturino",
"link":"http:\/\/www.schweitzerproject.com\/de\/company\/philosophy",
"link_portrait":null,
"logo":"schweitzer_logo_fhk.png",
"portrait":null
},
{
"title":"Handelsvertzreter\r\nTechnische Verkäufer\r\nim Bereich Holzhausbau (w\/m)",
"date":"2015\/12\/22",
"pdf":"2451_rubner.pdf",
"country":"Deutschland",
"company":"Rubner Haus AG",
"address":"DE-85586 Poing \/ Grub",
"link":"http:\/\/www.rubner.com\/de\/holzhausbau.html",
"link_portrait":null,
"logo":"rubner_haus_logo_fhk.png",
"portrait":null
},
{
"title":"Dachdecker (m\/w)",
"date":"2016\/1\/20",
"pdf":"1002_Ochs.pdf",
"country":"Deutschland",
"company":"OCHS GmbH",
"address":"DE-Kirchberg",
"link":"http:\/\/www.ochs.eu\/stellenangebote.html",
"link_portrait":null,
"logo":"Ochs_logo_fhk.gif",
"portrait":null
},
{
"title":"Schreiner (m\/w) für Bankraum, Maschinenraum und Montage",
"date":"2016\/1\/20",
"pdf":"769_Feederle.pdf",
"country":"Deutschland",
"company":"Paul Feederle GmbH",
"address":"DE-Karlsruhe",
"link":"http:\/\/www.feederle.de\/",
"link_portrait":null,
"logo":"Feederle_logo_fhk.gif",
"portrait":null
},
{
"title":"Schreiner (m\/w) für die Möbelmontage",
"date":"2016\/1\/20",
"pdf":"768_Feederle.pdf",
"country":"Deutschland",
"company":"Paul Feederle GmbH",
"address":"DE-Karlsruhe",
"link":"http:\/\/www.feederle.de\/",
"link_portrait":null,
"logo":"Feederle_logo_fhk.gif",
"portrait":null
},
{
"title":"Elektrotechniker\/in",
"date":"2015\/10\/21",
"pdf":"2012_pfeifer.pdf",
"country":"Österreich",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"AT-6250 Kundl\/Tirol",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmerer (m\/w)",
"date":"2016\/1\/20",
"pdf":"1001_Ochs.pdf",
"country":"Deutschland",
"company":"OCHS GmbH",
"address":"DE-Kirchberg",
"link":"http:\/\/www.ochs.eu\/stellenangebote.html",
"link_portrait":null,
"logo":"Ochs_logo_fhk.gif",
"portrait":null
},
{
"title":"Bauzeichner (m\/w) \r\n",
"date":"2016\/1\/27",
"pdf":"2374_schlosser.pdf",
"country":"Deutschland",
"company":"Schlosser Holzbau GmbH",
"address":"DE-Jagstzell",
"link":"http:\/\/www.schlosser-projekt.de\/de\/das_unternehmen\/stellenangebote.html",
"link_portrait":null,
"logo":"SchlosserI_logo_fhk.gif",
"portrait":null
},
{
"title":"Baggerfahrer (m\/w)",
"date":"2015\/7\/26",
"pdf":"1790_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Verkaufs-Innendienst (m\/w)",
"date":"2016\/1\/26",
"pdf":"2530_sturm.pdf",
"country":"Österreich",
"company":"Strum GmbH",
"address":"AT-5091 Unken",
"link":"http:\/\/www.funktionstueren.eu\/",
"link_portrait":null,
"logo":"sturm_logo_fhk.png",
"portrait":null
},
{
"title":"SharePoint Specialist (m\/w)\r\n",
"date":"2015\/9\/7",
"pdf":"2137_egger.pdf",
"country":"Österreich",
"company":"Fritz Egger GmbH & Co OG",
"address":"AT- 6380 St.Johann in Tirol",
"link":"http:\/\/www.egger.com\/shop\/de_AT\/offene-stellen",
"link_portrait":null,
"logo":"Egger_Logo_HuK.gif",
"portrait":null
},
{
"title":"Montageleier\/\r\nin",
"date":"2016\/1\/22",
"pdf":"1867_steininger.pdf",
"country":"Österreich",
"company":"steininger.designers gmbh",
"address":"AT-4113 St. Martin\/mkr., \r\n",
"link":"http:\/\/www.steininger-designers.at\/",
"link_portrait":null,
"logo":"logo_steininger_designers.gif",
"portrait":null
},
{
"title":"Kalkulation \/ Einkauf",
"date":"2016\/1\/13",
"pdf":"11304_treinnova.pdf",
"country":"Schweiz",
"company":"Tre Innova",
"address":"CH-Hünenberg",
"link":"http:\/\/www.treinnova.ch\/index.asp?page=jobboerse",
"link_portrait":null,
"logo":"TreInnova_Logo_HuK.gif",
"portrait":null
},
{
"title":"IT-Systemadministrator (m\/w)",
"date":"2015\/7\/26",
"pdf":"2042_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"CNC Maschinist (m\/w)",
"date":"2015\/12\/9",
"pdf":"2197_glaeser.pdf",
"country":"Schweiz",
"company":"GLAESER WOGG AG",
"address":"CH-Dättwil",
"link":"http:\/\/www.glaeser.ch",
"link_portrait":null,
"logo":"Glaeser_logo_fhk.gif",
"portrait":null
},
{
"title":"Mechatroniker \/ Elektrotechniker (m\/w)\r\nmit Schwerpunkt SPS, SPS-Techniker\r\nbzw. SPS-Fachkraft",
"date":"2016\/1\/22",
"pdf":"1713_Pollmeier.pdf",
"country":"Deutschland",
"company":"Pollmeier Massivholz GmbH & Co. KG",
"address":"DE-Aschaffenburg",
"link":"http:\/\/www.pollmeier.com\/de\/",
"link_portrait":null,
"logo":"pollmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Marketingreferent (m\/w)",
"date":"2016\/1\/26",
"pdf":"2527_pollmeier.pdf",
"country":"Deutschland",
"company":"Pollmeier Massivholz GmbH & Co. KG",
"address":"DE-München",
"link":"http:\/\/www.pollmeier.com\/de\/karriere\/ueber-uns\/\r\n",
"link_portrait":null,
"logo":"pollmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Technische\/n Verkäufer\/in \r\nNiederösterreich und\/oder\r\nSteiermark\r\n",
"date":"2015\/12\/7",
"pdf":"2193_rothoblass.pdf",
"country":"Österreich",
"company":"Rotho Blaas srl",
"address":"AT-Graz",
"link":"http:\/\/www.rothoblaas.com\/fr\/be\/home.html",
"link_portrait":null,
"logo":"rothoblaas_logo_fhk.gif",
"portrait":null
},
{
"title":"Fachberater Bauhandwerk NL Düsseldorf (m\/w)",
"date":"2016\/1\/20",
"pdf":"1933_doka.pdf",
"country":"Deutschland",
"company":"Deutsche Doka Schalungstechnik GmbH",
"address":"DE-Nürnberg Altdorf\r\n",
"link":"http:\/\/www.doka.com\/web\/about\/job-career\/careers-with-doka\/index.de.php",
"link_portrait":null,
"logo":"Doka_logo_fhk.gif",
"portrait":null
},
{
"title":"Möbeltischler (w\/m)",
"date":"2016\/2\/1",
"pdf":"2561_tischlerei_lenz.pdf",
"country":"Österreich",
"company":"Tischlerei Bernhard Lenz",
"address":"AT-8344 Bad Gleichenberg",
"link":"http:\/\/www.tischlerei-lenz.at",
"link_portrait":null,
"logo":"bernhard_lenz_logo_fhk.png",
"portrait":null
},
{
"title":"Maschinenführer (m\/w)",
"date":"2015\/7\/26",
"pdf":"2043_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Konstrukteur (m\/w)",
"date":"2016\/1\/13",
"pdf":"11216_Rubner.pdf",
"country":"Deutschland",
"company":"Rubner Holzbau GmbH",
"address":"DE-Augsburg",
"link":"http:\/\/www.holzbau.rubner.com",
"link_portrait":null,
"logo":"rubnerholzbau_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler (m\/w)",
"date":"2016\/2\/1",
"pdf":"2557_schober_holzbau.pdf",
"country":"Österreich",
"company":"Schober Holzbau GmbH",
"address":"AT-5211 Friedburg",
"link":"http:\/\/www.schober-holzbau.at\/",
"link_portrait":null,
"logo":"schober_holzbau_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmerer (m\/w)",
"date":"2016\/2\/1",
"pdf":"2557_schober_holzbau.pdf",
"country":"Österreich",
"company":"Schober Holzbau GmbH",
"address":"AT-5211 Friedburg",
"link":"http:\/\/www.schober-holzbau.at\/",
"link_portrait":null,
"logo":"schober_holzbau_logo_fhk.png",
"portrait":null
},
{
"title":"Verkaufsberater im Bereich Elektrowerkzeuge (m\/w)\r\n\r\n",
"date":"2016\/1\/7",
"pdf":"2457_cpm.pdf",
"country":"Schweiz",
"company":"CPM Switzerland AG",
"address":"Schweiz",
"link":"http:\/\/www.bosch.ch",
"link_portrait":null,
"logo":"cpm_logo_fhk.png",
"portrait":null
},
{
"title":"Mitarbeiter Vertrieb Innendienst (m\/w)",
"date":"2016\/1\/29",
"pdf":"2552_rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Betriebselektriker (m\/w)",
"date":"2015\/11\/26",
"pdf":"2358_pfeifer.pdf",
"country":"Deutschland",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"DE-86556 Unterbernbach",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"CNC-Maschinist, für die modernste Kistenproduktionsanlage in Europa",
"date":"2015\/12\/22",
"pdf":"2275_kifa.pdf",
"country":"Schweiz",
"company":"KIFA AG",
"address":"CH-Aadorf",
"link":"http:\/\/www.kifa.ch\/unternehmen\/job\/",
"link_portrait":null,
"logo":"Kifa_Logo_huk.gif",
"portrait":null
},
{
"title":"Handelsvertreter (m\/w) für Ulm\/Günzburg",
"date":"2016\/1\/20",
"pdf":"1555_Fischer.pdf",
"country":"Deutschland",
"company":"Fischerhaus",
"address":"DE-Bodenwöhr",
"link":"http:\/\/www.fischerhaus.de\/ueber-uns\/stellenangebote.html",
"link_portrait":null,
"logo":"Fischerhaus_logo_fhk.gif",
"portrait":null
},
{
"title":"Bauingenieur(m\/w) für den Vertrieb von Statiksoftware",
"date":"2016\/1\/20",
"pdf":"2398_dlubal.pdf",
"country":"Deutschland",
"company":"Ingenieur-Software Dlubal GmbH",
"address":"DE-Tiefenbach",
"link":"http:\/\/www.dlubal.de\/wir-suchen.aspx",
"link_portrait":null,
"logo":"dlubal_logo_fhk.gif",
"portrait":null
},
{
"title":"Bauzeichner \/ Arbeitsvorbereiter (m\/w)\r\n\r\n",
"date":"2016\/1\/27",
"pdf":"2245_haas.pdf",
"country":"Deutschland",
"company":"Haas Fertigbau GmbH",
"address":"DE-84326 Falkenberg",
"link":"http:\/\/haas-karriere.com\/hp409\/Stellenangebote.htm?ITServ=CY1685a477X14af3f233ceXY585e",
"link_portrait":null,
"logo":"Haas_logo_fhk.gif",
"portrait":null
},
{
"title":"Vertriebsmitarbeiter für Bayern (m\/w)",
"date":"2015\/6\/19",
"pdf":"2007_Blue.pdf",
"country":"Deutschland",
"company":"BlueLion Consult",
"address":"DE-Passau",
"link":null,
"link_portrait":null,
"logo":"holzkarriere_logo_fhk.gif",
"portrait":null
},
{
"title":"Schreiner \/ Tischler (m\/w)\r\n",
"date":"2016\/1\/14",
"pdf":"2475_bluepool.pdf",
"country":"Deutschland",
"company":"bluepool GmbH",
"address":"DE-Leinfelden-Echterdingen",
"link":"http:\/\/www.bluepool.de\/job",
"link_portrait":null,
"logo":"Bluepool_logo_fhk.gif",
"portrait":null
},
{
"title":"Vertriebsmitarbeiter Schwerpunkt Schnittholz (m\/w)\r\n\r\n",
"date":"2016\/1\/29",
"pdf":"2244_elka.pdf",
"country":"Deutschland",
"company":"Elka Holzwerke GmbH",
"address":"DE-Morbach",
"link":"http:\/\/www.elka-holzwerke.eu\/",
"link_portrait":null,
"logo":"Elka_Logo_HuK.gif",
"portrait":null
},
{
"title":"Maler m\/w (AllrounderIn!) ",
"date":"2016\/2\/1",
"pdf":"2560_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-St. Pölten",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Technische\/n Verkäufer\/in\r\nTirol - Vorarlberg\r\n",
"date":"2016\/1\/11",
"pdf":"2459_rothoblass.pdf",
"country":"Österreich",
"company":"Rotho Blaas GmbH\/srl",
"address":"AT-Tirol\/Voralberg",
"link":"http:\/\/www.rothoblaas.com\/de\/firma\/karriere.html",
"link_portrait":null,
"logo":"rothoblaas_logo_fhk.gif ",
"portrait":null
},
{
"title":"Maurer \/ Schalungszimmerer (w\/m)",
"date":"2016\/1\/25",
"pdf":"11671_strobl.pdf",
"country":"Österreich",
"company":"Strobl Bau - Holzbau GmbH",
"address":"AT-8160 Weiz",
"link":"http:\/\/www.strobl.at\/index.php",
"link_portrait":null,
"logo":"Strobl_Logo.gif",
"portrait":null
},
{
"title":"Verkaufstalent m\/w für den Aussendienst\r\nDeutschland Mitte-West",
"date":"2016\/1\/12",
"pdf":"2462_homatherm.pdf",
"country":"Deutschland",
"company":"HOMATHERM GmbH",
"address":"DE-Berga",
"link":"http:\/\/www.homatherm.com\/",
"link_portrait":null,
"logo":"Homatherm_logo_fhk.gif",
"portrait":null
},
{
"title":"Montageschreiner (m\/w) für In- und Ausland ",
"date":"2016\/1\/13",
"pdf":"2024_Dobergo.pdf",
"country":"Deutschland",
"company":"DOBERGO GmbH & Co. KG",
"address":"DE-Loßburg-Betzweiler",
"link":"http:\/\/www.dobergo.de\/",
"link_portrait":null,
"logo":"Dobergo_logo_fhk.gif",
"portrait":null
},
{
"title":"Holzbau-Verkäufer für die Westschweiz (m\/w)",
"date":"2015\/5\/1",
"pdf":"1861_timbercode.pdf",
"country":"Schweiz",
"company":"Blumer Lehmann AG",
"address":"CH-Gossau \/ Erlenhof",
"link":"http:\/\/www.blumer-lehmann.ch\/unternehmen\/job-und-karriere\/uebersicht\/",
"link_portrait":null,
"logo":"Blumer-Lehmann_Logo_huk.gif",
"portrait":null
},
{
"title":"Verkauf Europa (m\/w)",
"date":"2016\/1\/29",
"pdf":"2550_mynexxt.pdf",
"country":"Österreich",
"company":"myNEXXT",
"address":"AT-1060 Wien",
"link":"http:\/\/www.mynexxt.at\/job-for-leaders\/",
"link_portrait":null,
"logo":"mynexxt_logo_fhk.png",
"portrait":null
},
{
"title":"Sachbearbeiter Versand (m\/w)",
"date":"2016\/1\/29",
"pdf":"2551_rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Spezialist für Faserverbundtechnologie w\/m",
"date":"2015\/11\/25",
"pdf":"2347_flist.pdf",
"country":"Österreich",
"company":"F. LIST GMBH",
"address":"AT-2842 Thomasberg",
"link":"http:\/\/f-list.at\/job\/stellenangebote\/",
"link_portrait":null,
"logo":"flist_logo.png",
"portrait":null
},
{
"title":"Einkäufer Schnittholz (m\/w)",
"date":"2016\/1\/29",
"pdf":"2549_mynexxt.pdf",
"country":"Österreich",
"company":"myNEXXT",
"address":"AT-1060 Wien",
"link":"http:\/\/www.mynexxt.at\/job-for-leaders\/",
"link_portrait":null,
"logo":"mynexxt_logo_fhk.png",
"portrait":null
},
{
"title":"Verkäufer Fenster + Fassaden (m\/w)",
"date":"2016\/2\/4",
"pdf":"2046_erne.pdf",
"country":"Schweiz",
"company":"ERNE AG Holzbau",
"address":"CH-Laufenburg",
"link":"http:\/\/www.erne.net",
"link_portrait":null,
"logo":"ERNE_CMYK.gif",
"portrait":null
},
{
"title":"Vertriebs-Assistent (m\/w) Internationaler Ingenieurholzbau",
"date":"2015\/11\/23",
"pdf":"2027_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"E-Commerce-Consultant (m\/w)",
"date":"2015\/11\/23",
"pdf":"1895_egger.pdf",
"country":"Österreich",
"company":"Fritz Egger GmbH & Co OG",
"address":"AT- 6380 St.Johann in Tirol",
"link":"http:\/\/www.egger.com\/shop\/de_AT\/offene-stellen",
"link_portrait":null,
"logo":"Egger_Logo_HuK.gif",
"portrait":null
},
{
"title":"Mitarbeiter im Bereich Produktionsplanung &-Steuerung (m\/w)",
"date":"2016\/1\/25",
"pdf":"2526_ilim.pdf",
"country":"Deutschland",
"company":"IIlim Timber Bavaria GmbH",
"address":"DE-Landsberg am Lech",
"link":"http:\/\/www.ilimtimber.com\/de\/karriere\/Jobs\/",
"link_portrait":null,
"logo":"Ilim_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter\/in im Verkaufsinnendienst\r\nTüren und Plattenwerkstoffe\r\n",
"date":"2015\/12\/7",
"pdf":"2406_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-8055 Graz",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Aussendienstmitarbeiter Holzbau und Zimmerei Nordwestschweiz (BL\/BS)",
"date":"2016\/1\/5",
"pdf":"2149_fehrbraunwalder.pdf",
"country":"Schweiz",
"company":"Fehr Braunwalder AG",
"address":"CH-St. Gallen",
"link":"http:\/\/www.fehrbraunwalder.ch\/",
"link_portrait":null,
"logo":"FehrBraunwalderAG_logo_fhk.gif",
"portrait":null
},
{
"title":"Lagerleiter\/in",
"date":"2015\/12\/22",
"pdf":"2452_frischeis.pdf",
"country":"Bulgarien",
"company":"J.u.A. Frischeis GmbH",
"address":"Bulgarien \/ Sofia",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter Holzbauabteilung",
"date":"2015\/12\/15",
"pdf":"2437_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-4020 Linz",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmerergeselle (m\/w)  ",
"date":"2016\/1\/25",
"pdf":"2519_vorholz.pdf",
"country":"Deutschland",
"company":"Holzbau Vorholz Hawran GmbH",
"address":"DE-Geretsried",
"link":"http:\/\/www.vorholz-hawran.de\/online-bewerben.html\r\n",
"link_portrait":null,
"logo":"Hawran_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmerer\/Schreiner (m\/w) für die Montage ",
"date":"2016\/1\/25",
"pdf":"2517_baufritz.pdf",
"country":"Deutschland",
"company":"Baufritz GmbH & Co. KG",
"address":"DE-Erkheim",
"link":"http:\/\/www.baufritz.com\/de\/service-events\/ausbildung-praktikum\/praktikumsstellen\/",
"link_portrait":null,
"logo":"Baufritz_logo_fhk.gif",
"portrait":null
},
{
"title":"Spengler\/Dachdecker (m\/w)  ",
"date":"2016\/1\/25",
"pdf":"2518_vorholz.pdf",
"country":"Deutschland",
"company":"Holzbau Vorholz Hawran GmbH",
"address":"DE-Geretsried",
"link":"http:\/\/www.vorholz-hawran.de\/online-bewerben.html\r\n",
"link_portrait":null,
"logo":"Hawran_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler (m\/w)",
"date":"2016\/1\/22",
"pdf":"2045_holzkoepfe.pdf",
"country":"Österreich",
"company":"Die Holzköpfe GmbH",
"address":"AT-5211 Friedburg",
"link":"http:\/\/www.die-holzkoepfe.at\/",
"link_portrait":null,
"logo":"holzkoepfe_logo_fhk.png",
"portrait":null
},
{
"title":"CNC Techniker\/in",
"date":"2016\/2\/4",
"pdf":"2587_kranz.pdf",
"country":"Österreich",
"company":"Kranz Tischlerei GmbH. & Co.KG",
"address":"AT-4690 Schwanenstadt",
"link":"http:\/\/www.kastenfenster.at\/",
"link_portrait":null,
"logo":"kranz_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmerer (m\/w)",
"date":"2016\/1\/22",
"pdf":"2045_holzkoepfe.pdf",
"country":"Österreich",
"company":"Die Holzköpfe GmbH",
"address":"AT-5211 Friedburg",
"link":"http:\/\/www.die-holzkoepfe.at\/",
"link_portrait":null,
"logo":"holzkoepfe_logo_fhk.png",
"portrait":null
},
{
"title":"Trainee SAP Competence Center Logistik (m\/w)",
"date":"2016\/2\/2",
"pdf":"2565_egger.pdf",
"country":"Österreich",
"company":"Fritz Egger GmbH & Co OG",
"address":"AT- 6380 St.Johann in Tirol",
"link":"http:\/\/www.egger.com\/shop\/de_AT\/offene-stellen",
"link_portrait":null,
"logo":"Egger_Logo_HuK.gif",
"portrait":null
},
{
"title":"Bankschreiner mit CNC-Erfahrung (m\/w)",
"date":"2016\/1\/29",
"pdf":"2553_bandi.pdf",
"country":"Schweiz",
"company":"Bandi Ladenbau AG",
"address":"CH-Oberwil b. Büren",
"link":"http:\/\/www.bandi-ladenbau.ch\/con\/cms\/front_content.php?idcat=13&lang=1",
"link_portrait":null,
"logo":"bandi_logo_fhk.png",
"portrait":null
},
{
"title":"Industriemeister der Holzbe- oder Verarbeitung als Nachwuchskraft (m\/w)",
"date":"2015\/7\/26",
"pdf":"1714_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Wilburgstetten",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter\/in im Verkaufsinnendienst-Bereich Türen",
"date":"2015\/7\/14",
"pdf":"2054_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-8055 Graz",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Handelsvertreter \r\nTechnische Verkäufer im Bereich Holzhausbau (w\/m)",
"date":"2016\/1\/26",
"pdf":"2528_rubner.pdf",
"country":"Deutschland",
"company":"Rubner Haus AG",
"address":"DE-85586 Poing \/ Grub",
"link":"http:\/\/www.rubner.com\/de\/holzhausbau.html",
"link_portrait":null,
"logo":"rubner_haus_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmerer \/ Zimmererpolier (m\/w)",
"date":"2016\/1\/27",
"pdf":"2386_schlosser.pdf",
"country":"Deutschland",
"company":"Schlosser Holzbau GmbH",
"address":"DE-Jagstzell",
"link":"http:\/\/www.schlosser-projekt.de\/de\/das_unternehmen\/stellenangebote.html",
"link_portrait":null,
"logo":"SchlosserI_logo_fhk.gif",
"portrait":null
},
{
"title":"Maler (m\/w)",
"date":"2016\/1\/15",
"pdf":"2483_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Baggerfahrer (m\/w)",
"date":"2015\/7\/26",
"pdf":"1902_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Statiker Vollzeit (w\/m)\r\n",
"date":"2015\/10\/20",
"pdf":"2230_fischerhaus.pdf",
"country":"Deutschland",
"company":"Fischerhaus",
"address":"DE-Bodenwöhr",
"link":"http:\/\/www.fischerhaus.de\/ueber-uns\/stellenangebote.html",
"link_portrait":null,
"logo":"Fischerhaus_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler \/ in",
"date":"2015\/11\/30",
"pdf":"2376_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Sekretär \/ Assistenten \r\nder Geschäftsleitung (m \/ w)",
"date":"2015\/9\/15",
"pdf":"2158_bernd_gruber.pdf",
"country":"Österreich",
"company":" Bernd Gruber GmbH ",
"address":"AT-6371 Aurach-Kitzbühel ",
"link":"http:\/\/www.bernd-gruber.at\/service\/karriere\/",
"link_portrait":null,
"logo":"bernd_gruber_logo.png",
"portrait":null
},
{
"title":"Industriemechaniker Betriebstechnik (w\/m)",
"date":"2015\/11\/7",
"pdf":"2284_ilim.pdf",
"country":"Deutschland",
"company":"IIlim Timber Bavaria GmbH",
"address":"DE-Landsberg am Lech",
"link":"http:\/\/www.ilimtimber.com\/de\/karriere\/Jobs\/",
"link_portrait":null,
"logo":"Ilim_logo_fhk.gif",
"portrait":null
},
{
"title":"Assistent Technische Werksleitung (m\/w)\r\nin Gagarin \/ Russland",
"date":"2016\/2\/2",
"pdf":"1896_egger.pdf",
"country":"Russland",
"company":"OOO \"EGGER Drevprodukt\"",
"address":"215010 Gagarin, Oblast Smolensk, Russia",
"link":"http:\/\/www.egger.com\/AT_de\/karriere-bei-egger.htm",
"link_portrait":null,
"logo":"Egger_Logo_HuK.gif",
"portrait":null
},
{
"title":"Aussendienstmitarbeiter (w\/m)",
"date":"2015\/12\/3",
"pdf":"2381_schweighofer.pdf",
"country":"Österreich",
"company":"Holzindustrie Schweighofer",
"address":"D-Görlitz-Sachsen \/ A-Wien",
"link":"http:\/\/www.schweighofer.at\/deutsch\/job\/index.html",
"link_portrait":null,
"logo":"Schweighofer_Logo_HuK.gif",
"portrait":null
},
{
"title":"Betriebsschlosser (m\/w)",
"date":"2015\/7\/26",
"pdf":"1916_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter für den Bereich Zuschnitt und Bekantung\r\n\r\n",
"date":"2016\/1\/11",
"pdf":"2458_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-2000 Stockerau",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Technischer Zeichner (m\/w)",
"date":"2016\/2\/1",
"pdf":"2557_schober_holzbau.pdf",
"country":"Österreich",
"company":"Schober Holzbau GmbH",
"address":"AT-5211 Friedburg",
"link":"http:\/\/www.schober-holzbau.at\/",
"link_portrait":null,
"logo":"schober_holzbau_logo_fhk.png",
"portrait":null
},
{
"title":"Lackierer (m\/w)",
"date":"2016\/1\/28",
"pdf":"1740_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Techniker für Zimmerei \/ Holzbau (m\/w) ",
"date":"2016\/1\/28",
"pdf":"1753_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"Tapezierer - Textil, Leder (m\/w)",
"date":"2015\/10\/19",
"pdf":"1741_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Tischler \/ Tischlereitechniker für Produktion (m\/w)",
"date":"2016\/2\/3",
"pdf":"1743_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Kostenrechnung für die Produktion",
"date":"2016\/2\/3",
"pdf":"1898_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Produktionsmitarbeiter (m\/w)",
"date":"2016\/2\/1",
"pdf":"2562_stuhl.pdf",
"country":"Österreich",
"company":"Längle & Hagspiel",
"address":"AT-6973 Höchst",
"link":"http:\/\/www.stuhl.at\/",
"link_portrait":null,
"logo":"LH_Logo_FHK.gif",
"portrait":null
},
{
"title":"Kostenrechnung für die Produktion",
"date":"2015\/11\/23",
"pdf":"1898_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Bodenleger oder Tischler m\/w (AllrounderIn!) ",
"date":"2016\/2\/4",
"pdf":"2584_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Vertriebsassistenz",
"date":"2016\/2\/2",
"pdf":"2566_knapp.pdf",
"country":"Deutschland",
"company":"Knapp GmbH",
"address":"DE-85591 Vaterstetten",
"link":"http:\/\/www.knapp-verbinder.com\/",
"link_portrait":null,
"logo":"Knapp_logo_fhk.gif",
"portrait":null
},
{
"title":"Vertriebsassistenz (Teil- oder Vollzeit)",
"date":"2016\/2\/2",
"pdf":"2567_knapp.pdf",
"country":"Österreich",
"company":"Knapp GmbH",
"address":"AT-3324 Euratsfeld",
"link":"http:\/\/www.knapp-verbinder.com",
"link_portrait":null,
"logo":"Knapp_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter\/In für\r\nPlattenzuschnitt & -bekantung\r\n",
"date":"2015\/8\/27",
"pdf":"2009_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-4020 Linz",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Personalverrechner (m\/w) Teilzeit 30 Std.\r\n",
"date":"2016\/1\/28",
"pdf":"1807_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"Technische\/n Verkäufer\/in Raum Graz\r\n",
"date":"2015\/12\/7",
"pdf":"2192_rothoblass.pdf",
"country":"Österreich",
"company":"Rotho Blaas srl",
"address":"AT-Graz",
"link":"http:\/\/www.rothoblaas.com\/fr\/be\/home.html",
"link_portrait":null,
"logo":"rothoblaas_logo_fhk.gif",
"portrait":null
},
{
"title":"Holzfachkraft für Lagerleitung",
"date":"2016\/2\/3",
"pdf":"2412_altholz.pdf",
"country":"Österreich",
"company":"Altholz - Baumgartner & Co GmbH",
"address":"AT-4553 Schierbach",
"link":"http:\/\/www.altholz.net\/unternehmen\/job-bei-altholz\/",
"link_portrait":null,
"logo":"altholz_logo.png",
"portrait":null
},
{
"title":"Einkäufer Stahlbau (m\/w)\r\n",
"date":"2015\/10\/2",
"pdf":"2190_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"Elektroniker Betriebstechnik (Automatisierer) m\/w)",
"date":"2015\/7\/26",
"pdf":"2017_Rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Ramstein GmbH",
"address":"DE-Ramstein-Miesenbach",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Konstrukteur Hochbau (m\/w) \r\n",
"date":"2016\/1\/27",
"pdf":"2372_schlosser.pdf",
"country":"Deutschland",
"company":"Schlosser Holzbau GmbH",
"address":"DE-Jagstzell",
"link":"http:\/\/www.schlosser-projekt.de\/de\/das_unternehmen\/stellenangebote.html",
"link_portrait":null,
"logo":"SchlosserI_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmerer, Schreiner (m\/w)",
"date":"2015\/11\/26",
"pdf":"1834_Hess.pdf",
"country":"Deutschland",
"company":"HESS TIMBER GmbH & Co. KG",
"address":"DE-Kleinheubach",
"link":"http:\/\/www.hess-timber.com\/",
"link_portrait":null,
"logo":"HessTimber_logo_fhk.gif",
"portrait":null
},
{
"title":"Vorarbeiter\/Zimmerpolier 100% (m\/w)",
"date":"2015\/12\/12",
"pdf":"2122_stuber.pdf",
"country":"Schweiz",
"company":"Stuber & Cie AG Holzbau",
"address":"CH-Schüpfen",
"link":"http:\/\/www.stuberholz.ch\/unternehmen\/team\/",
"link_portrait":null,
"logo":"stuberholz_logo_fhk.png",
"portrait":null
},
{
"title":"Mitarbeiter für Angebotskalkulation (m\/w)",
"date":"2016\/1\/28",
"pdf":"1739_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"Schreiner EFZ (w\/m)",
"date":"2016\/1\/22",
"pdf":"2510_hartmann.pdf",
"country":"Schweiz",
"company":"Hartmann Schreinerei und Innenausbau AG",
"address":"CH-Eglisau",
"link":"http:\/\/hartmann-projekte.ch\/",
"link_portrait":null,
"logo":"hartmann_logo_fhk.png",
"portrait":null
},
{
"title":"Produktionsfacharbeiter (m\/w)",
"date":"2016\/2\/3",
"pdf":"2439_schmid_schrauben.pdf",
"country":"Österreich",
"company":"Schmid Schrauben Hainfeld GmbH",
"address":"AT-3170 Hainfeld",
"link":"http:\/\/www.schrauben.at\/was_wir_verbinden_haelt\/karriere",
"link_portrait":null,
"logo":"schmid_schrauben_logo_fhk.png",
"portrait":null
},
{
"title":"Sachbearbeiter\/in im technischen Verkauf",
"date":"2016\/2\/3",
"pdf":"2440_schmid_schrauben.pdf",
"country":"Österreich",
"company":"Schmid Schrauben Hainfeld GmbH",
"address":"AT-3170 Hainfeld",
"link":"http:\/\/www.schrauben.at\/was_wir_verbinden_haelt\/karriere",
"link_portrait":null,
"logo":"schmid_schrauben_logo_fhk.png",
"portrait":null
},
{
"title":"Mitarbeiter\/In Technik\/Konstruktion",
"date":"2016\/2\/3",
"pdf":"2441_schmid_schrauben.pdf",
"country":"Österreich",
"company":"Schmid Schrauben Hainfeld GmbH",
"address":"AT-3170 Hainfeld",
"link":"http:\/\/www.schrauben.at\/was_wir_verbinden_haelt\/karriere",
"link_portrait":null,
"logo":"schmid_schrauben_logo_fhk.png",
"portrait":null
},
{
"title":"Mitarbeiter\/In Technik\/Konstruktion",
"date":"2015\/12\/17",
"pdf":"2441_schmid_schrauben.pdf",
"country":"Österreich",
"company":"Schmid Schrauben Hainfeld GmbH",
"address":"AT-3170 Hainfeld",
"link":"http:\/\/www.schrauben.at\/was_wir_verbinden_haelt\/karriere",
"link_portrait":null,
"logo":"schmid_schrauben_logo_fhk.png",
"portrait":null
},
{
"title":"Facharbeiter (m\/w)",
"date":"2015\/9\/10",
"pdf":"2146_hasslacher.pdf",
"country":"Österreich",
"company":"Hasslacher Norica Timber - Holzbausysteme GmbH",
"address":"AT- 9620 Hermagor",
"link":"http:\/\/www.hasslacher.at",
"link_portrait":null,
"logo":"Hasslacher_Logo_fhk.gif",
"portrait":"HASSLACHER Holzbausysteme GmbH"
},
{
"title":"Versandmitarbeiter (m\/w)",
"date":"2016\/2\/1",
"pdf":"2564_stuhl.pdf",
"country":"Österreich",
"company":"Längle & Hagspiel",
"address":"AT-6973 Höchst",
"link":"http:\/\/www.stuhl.at\/",
"link_portrait":null,
"logo":"LH_Logo_FHK.gif",
"portrait":null
},
{
"title":"Maler m\/w (AllrounderIn!) für Kapfenberg",
"date":"2015\/10\/12",
"pdf":"2213_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Tischler \/ Tischlereitechniker für Arbeitsvorbereitung (m\/w)",
"date":"2016\/2\/3",
"pdf":"1742_list.pdf",
"country":"Österreich",
"company":"LIST General Contractor GmbH",
"address":"AT-Bad Erlach",
"link":"http:\/\/www.list.at\/Karriere\/Stellenanzeigen",
"link_portrait":null,
"logo":"list_logo.gif",
"portrait":null
},
{
"title":"CNC-Maschinist, für eine 6-Achs Hundegger Robot-Drive Abbundanlage",
"date":"2015\/12\/22",
"pdf":"2274_kifa.pdf",
"country":"Schweiz",
"company":"KIFA AG",
"address":"CH-Aadorf",
"link":"http:\/\/www.kifa.ch\/unternehmen\/job\/",
"link_portrait":null,
"logo":"Kifa_Logo_huk.gif",
"portrait":null
},
{
"title":"Zimmerer (m\/w)",
"date":"2016\/1\/15",
"pdf":"2486_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmerer\/in",
"date":"2015\/9\/18",
"pdf":"2165_handlerbau.pdf",
"country":"Österreich",
"company":"Ing. W.P. Handler Bauges.m.b.H.",
"address":"AT-7343 Neutal",
"link":"http:\/\/www.handlerbau.at\/index.php?id=60",
"link_portrait":null,
"logo":"handler_logo.png",
"portrait":null
},
{
"title":"Maschinist (m\/w) Abbundanlage",
"date":"2015\/9\/18",
"pdf":"2166_handlerbau.pdf",
"country":"Österreich",
"company":"Ing. W.P. Handler Bauges.m.b.H.",
"address":"AT-7343 Neutal",
"link":"http:\/\/www.handlerbau.at\/index.php?id=60",
"link_portrait":null,
"logo":"handler_logo.png",
"portrait":null
},
{
"title":"Maler m\/w (AllrounderIn!) ",
"date":"2016\/2\/4",
"pdf":"2583_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Verkaufstalent m\/w für den Aussendienst\r\nDeutschland Süd-West",
"date":"2016\/1\/12",
"pdf":"2463_homatherm.pdf",
"country":"Deutschland",
"company":"HOMATHERM GmbH",
"address":"DE-Berga",
"link":"http:\/\/www.homatherm.com\/",
"link_portrait":null,
"logo":"Homatherm_logo_fhk.gif",
"portrait":null
},
{
"title":"Lackierer\/in",
"date":"2015\/9\/24",
"pdf":"2173_flist.pdf",
"country":"Österreich",
"company":"F. LIST GMBH",
"address":"AT-2842 Thomasberg",
"link":"http:\/\/f-list.at\/job\/stellenangebote\/",
"link_portrait":null,
"logo":"flist_logo.png",
"portrait":null
},
{
"title":"Kundenschreiner \/ Schreiner-Monteur (m\/w)",
"date":"2016\/1\/22",
"pdf":"2511_hugentobler.pdf",
"country":"Schweiz",
"company":"Hugentobler AG Küche Bad Wohnen",
"address":"CH-Braunau b. Wil",
"link":"http:\/\/www.schreiner-hugentobler.ch\/offene-stellen.html",
"link_portrait":null,
"logo":"hugentobler_logo_fhk.png",
"portrait":null
},
{
"title":"Tischler Facharbeiter (m\/w)",
"date":"2016\/1\/21",
"pdf":"2506_zeibich.pdf",
"country":"Österreich",
"company":"Tischlerei Zeibich GmbH",
"address":"AT-1160 Wien",
"link":"http:\/\/www.zeibich.at\/",
"link_portrait":null,
"logo":"zeibich_logo_fhk.png",
"portrait":null
},
{
"title":"Holzbaukonstrukteur (m\/w)\r\n\r\n",
"date":"2016\/1\/20",
"pdf":"2249_fischerhaus.pdf",
"country":"Deutschland",
"company":"Fischerhaus",
"address":"DE-Bodenwöhr",
"link":"http:\/\/www.fischerhaus.de\/ueber-uns\/stellenangebote.html",
"link_portrait":null,
"logo":"Fischerhaus_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler Arbeitsvorbereitung (m\/w)",
"date":"2016\/1\/21",
"pdf":"2507_zeibich.pdf",
"country":"Österreich",
"company":"Tischlerei Zeibich GmbH",
"address":"AT-1160 Wien",
"link":"http:\/\/www.zeibich.at\/",
"link_portrait":null,
"logo":"zeibich_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmermann (w\/m)",
"date":"2016\/1\/22",
"pdf":"2320_Kuenzli.pdf",
"country":"Schweiz",
"company":"Künzli Davos",
"address":"CH-Davos",
"link":"http:\/\/www.kuenzli-davos.ch\/unternehmen\/job-karriere\/",
"link_portrait":"http:\/\/www.kuenzli-davos.ch",
"logo":"Kuenzli_Davos_Logo_HuK.gif",
"portrait":"Mit Holz. Um die Vorteile seiner Lebendigkeit und Natürlichkeit für Sie ausschöpfen zu können, braucht es Sachkompetenz und Freude am Material. Wir wissen um die fast unbe- schränkten Einsatzmöglichkeiten des Holzes und setzen diese lebenskonform um. Dabei begleiten und beraten wir Sie umfassend von der Idee bis zum fertigen Objekt."
},
{
"title":"Assistent der Betriebsleitung (m\/w)",
"date":"2016\/1\/15",
"pdf":"2321_hain.pdf",
"country":"Deutschland",
"company":"Hain Industrieprodukte Vertriebs- GmbH",
"address":"DE-Rott am Inn",
"link":"http:\/\/www.hain.de\/stellenmarkt\/",
"link_portrait":null,
"logo":"Hain_logo_fhk.gif",
"portrait":null
},
{
"title":"Technischer Kundenberater Fenstertechnik (m\/w)",
"date":"2015\/12\/21",
"pdf":"2448_rehau.pdf",
"country":"Deutschland",
"company":"REHAU AG + CO",
"address":"DE-Rehau",
"link":"http:\/\/job.rehau.com\/",
"link_portrait":null,
"logo":"Rehau_logo_fhk.gif",
"portrait":null
},
{
"title":"Technischen Zeichner (m\/w)",
"date":"2016\/2\/1",
"pdf":"2082_bernd_gruber.pdf",
"country":"Österreich",
"company":" Bernd Gruber GmbH ",
"address":"AT-6371 Aurach-Kitzbühel ",
"link":"http:\/\/www.bernd-gruber.at\/service\/karriere\/",
"link_portrait":null,
"logo":"bernd_gruber_logo.png",
"portrait":null
},
{
"title":"Mitarbeiter Vertrieb Innendienst Export (m\/w)",
"date":"2016\/1\/22",
"pdf":"2201_pollmeier.pdf",
"country":"Deutschland",
"company":"Pollmeier Massivholz GmbH & Co. KG",
"address":"DE-Creuzburg",
"link":"http:\/\/www.pollmeier.com\/de\/",
"link_portrait":null,
"logo":"pollmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Maler m\/w ",
"date":"2015\/11\/30",
"pdf":"2375_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Controller (m\/w)",
"date":"2015\/11\/13",
"pdf":"2310_rodenberg.pdf",
"country":"Deutschland",
"company":"claus rodenberg waldkontor gmbh",
"address":"DE-Kastorf\r\n",
"link":"http:\/\/waldkontor.com\/de\/mitarbeiter\/job.htm",
"link_portrait":null,
"logo":"waldkontor_logo_fhk.gif",
"portrait":null
},
{
"title":"Verputzer (m\/w)",
"date":"2016\/1\/15",
"pdf":"2484_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Meister\/Techniker (m\/w)im Bereich Heizung-Lüftung-Sanitär\r\n",
"date":"2016\/1\/27",
"pdf":"2281_fischerhaus.pdf",
"country":"Deutschland",
"company":"Fischerhaus",
"address":"DE-Bodenwöhr",
"link":"http:\/\/www.fischerhaus.de\/ueber-uns\/stellenangebote.html",
"link_portrait":null,
"logo":"Fischerhaus_logo_fhk.gif",
"portrait":null
},
{
"title":"Allroundkraft im Büro (m\/w)",
"date":"2016\/1\/27",
"pdf":"2533_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-5101 Salzburg",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Betriebselektriker (m\/w)",
"date":"2016\/1\/27",
"pdf":"2279_gmach.pdf",
"country":"Deutschland",
"company":"Holzwerke Gmach GmbH",
"address":"DE-Pösing",
"link":"http:\/\/www.holzwerke-gmach.de\/17_karriere.php",
"link_portrait":null,
"logo":"Gmach_logo_fhk.gif",
"portrait":null
},
{
"title":"Bautischler (m\/w)",
"date":"2016\/1\/15",
"pdf":"2485_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Parkettleger (m\/w)\r\n",
"date":"2016\/1\/25",
"pdf":"2409_gruber.pdf",
"country":"Deutschland",
"company":"Gruber Naturholzhaus GmbH",
"address":"DE-Rötz",
"link":"http:\/\/www.gruber-group.de\/karriere\/",
"link_portrait":null,
"logo":"gruber_logo_fhk.gif",
"portrait":null
},
{
"title":"Obermonteur Trockenbau (m\/w)\r\n",
"date":"2016\/1\/25",
"pdf":"2410_gruber.pdf",
"country":"Deutschland",
"company":"Gruber Naturholzhaus GmbH",
"address":"DE-Rötz",
"link":"http:\/\/www.gruber-group.de\/karriere\/",
"link_portrait":null,
"logo":"gruber_logo_fhk.gif",
"portrait":null
},
{
"title":"Bodenleger m\/w (AllrounderIn!) für Kapfenberg",
"date":"2015\/10\/12",
"pdf":"2214_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"MaurerIn (Fliesenlegerkenntnissen) oder FliesenlegerIn (Maurerkenntnissen) für Kapfenberg",
"date":"2015\/10\/12",
"pdf":"2215_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"HTL-Absolvent\/in bzw. Junior Bautechniker\/in (Brand- und Wasserschadensanierung) für Graz",
"date":"2015\/10\/12",
"pdf":"2216_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Steiermark",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Gebietsleiter Holzbau (w\/m)",
"date":"2016\/2\/4",
"pdf":"2582_rigips.pdf",
"country":"Österreich",
"company":"Saint-Gobain Rigips Austria GmbH",
"address":"AT-8990 Bad Aussee",
"link":"http:\/\/www.rigips.com\/",
"link_portrait":null,
"logo":"Rigips_logo_fhk.gif",
"portrait":null
},
{
"title":"Mitarbeiter\/in Lager - Bereich Platte\r\n",
"date":"2015\/10\/13",
"pdf":"2222_frischeis.pdf",
"country":"Österreich",
"company":"J.u.A. Frischeis GmbH",
"address":"AT-6233 Kramsach",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Sachbearbeiter Qualitätswesen für die Endmontage (w\/m)",
"date":"2016\/1\/25",
"pdf":"2382_neurath.pdf",
"country":"Deutschland",
"company":"König + Neurath AG",
"address":"DE-Karben",
"link":"http:\/\/www.koenig-neurath.de\/de\/unternehmen\/index.html",
"link_portrait":null,
"logo":"Koenig_Neurath_Logo_fhk.gif",
"portrait":null
},
{
"title":"Aussendienst Fachberater (m\/w) ",
"date":"2015\/11\/20",
"pdf":"2323_krueger.pdf",
"country":"Deutschland",
"company":"Dr. Krüger Personalberatungsunternehmen",
"address":"DE-Kassel",
"link":"http:\/\/www.krueger-personalberatung.de",
"link_portrait":null,
"logo":"krueger_logo_fhk.gif",
"portrait":null
},
{
"title":"Mechatroniker \/ Elektrotechniker (m\/w) ",
"date":"2015\/11\/20",
"pdf":"2324_pollmeier.pdf",
"country":"Deutschland",
"company":"Pollmeier Massivholz GmbH & Co. KG",
"address":"DE-Aschaffenburg",
"link":"http:\/\/www.pollmeier.com\/de\/",
"link_portrait":null,
"logo":"pollmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Schlosser (w\/m)\r\n",
"date":"2015\/10\/20",
"pdf":"2232_merkle.pdf",
"country":"Deutschland",
"company":"projekt holzbau merkle gmbH",
"address":"DE-Bissingen u. Teck",
"link":"http:\/\/www.projekt-holzbau.de\/",
"link_portrait":null,
"logo":"Merkle_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler\/in für Produktion und Arbeitsvorbereitung ",
"date":"2016\/2\/4",
"pdf":"2586_kranz.pdf",
"country":"Österreich",
"company":"Kranz Tischlerei GmbH. & Co.KG",
"address":"AT-4690 Schwanenstadt",
"link":"http:\/\/www.kastenfenster.at\/",
"link_portrait":null,
"logo":"kranz_logo_fhk.png",
"portrait":null
},
{
"title":"Lageristen\/in\r\n",
"date":"2015\/10\/20",
"pdf":"2234_steininger_designers.pdf",
"country":"Österreich",
"company":"steininger.designers gmbh",
"address":"AT-4113 St. Martin\/mkr., \r\n",
"link":"http:\/\/www.steininger-designers.at\/",
"link_portrait":null,
"logo":"logo_steininger_designers.gif",
"portrait":null
},
{
"title":"Einkäufer Subunternehmerleistungen (w\/m)\r\n\r\n",
"date":"2015\/10\/27",
"pdf":"2246_wiehag.pdf",
"country":"Österreich",
"company":"Wiehag GmbH",
"address":"AT-4950 Altheim OÖ",
"link":"http:\/\/www.wiehag.com\/karriere\/spread-your-career.html",
"link_portrait":null,
"logo":"Wiehag_Logo.gif",
"portrait":null
},
{
"title":"Arbeitsvorbereiter (m\/w)\r\n\r\n",
"date":"2015\/10\/27",
"pdf":"2250_Pfeiffer.pdf",
"country":"Deutschland",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"DE-86556 Unterbernbach",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"Tragwerksplaner (m\/w) \r\n",
"date":"2016\/1\/27",
"pdf":"2373_schlosser.pdf",
"country":"Deutschland",
"company":"Schlosser Holzbau GmbH",
"address":"DE-Jagstzell",
"link":"http:\/\/www.schlosser-projekt.de\/de\/das_unternehmen\/stellenangebote.html",
"link_portrait":null,
"logo":"SchlosserI_logo_fhk.gif",
"portrait":null
},
{
"title":"Spengler (m\/w)",
"date":"2016\/1\/15",
"pdf":"2482_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Verkaufsprofi im Holzbereich\r\n",
"date":"2015\/12\/7",
"pdf":"2407_frischeis.pdf",
"country":"Bulgarien",
"company":"J.u.A. Frischeis GmbH",
"address":"Bulgarien \/ Sofia",
"link":"http:\/\/www.frischeis.com\/jaf\/karriere\/internationale-stellenangebote\/",
"link_portrait":null,
"logo":"Frischeis_logo_fhk.gif",
"portrait":null
},
{
"title":"Holzmechaniker\/Tischler (w\/m) mit CNC Kenntnissen",
"date":"2016\/1\/25",
"pdf":"2383_neurath.pdf",
"country":"Deutschland",
"company":"König + Neurath AG",
"address":"DE-Karben",
"link":"http:\/\/www.koenig-neurath.de\/de\/unternehmen\/index.html",
"link_portrait":null,
"logo":"Koenig_Neurath_Logo_fhk.gif",
"portrait":null
},
{
"title":"Vertriebsmitarbeiter (m\/w) Bereich Sperrholz Neu-Ulm\r\n\r\n",
"date":"2016\/2\/3",
"pdf":"2418_gotz.pdf",
"country":"Deutschland",
"company":"Carl Götz GmbH",
"address":"DE-Neu Ulm\r\n",
"link":"http:\/\/www.carlgoetz.de\/job\/stellenangebote.html",
"link_portrait":null,
"logo":"Goetz_logo_fhk.gif",
"portrait":null
},
{
"title":"Bodenleger\/in mit Malerkenntnissen ",
"date":"2016\/2\/1",
"pdf":"2554_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-St. Pölten",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Tischler (m\/w)",
"date":"2016\/2\/1",
"pdf":"2081_bernd_gruber.pdf",
"country":"Österreich",
"company":" Bernd Gruber GmbH ",
"address":"AT-5724 Stuhlfelden ",
"link":"http:\/\/www.bernd-gruber.at\/service\/karriere\/",
"link_portrait":null,
"logo":"bernd_gruber_logo.png",
"portrait":null
},
{
"title":"Elektrotechniker\/in (Elektro-Planung) ",
"date":"2015\/11\/2",
"pdf":"2269_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Gebäudetechniker\/in (HKLS-Planung) ",
"date":"2015\/11\/2",
"pdf":"2269_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Disponent\/in technische Einsatzplanung mit Bauerfahrung",
"date":"2015\/11\/2",
"pdf":"2271_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Wien",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Bautechniker (Sanierung)",
"date":"2016\/1\/25",
"pdf":"2515_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Salzburg",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"technischer Mitarbeiter (m\/w) in der Arbeitsvorbereitung",
"date":"2015\/11\/11",
"pdf":"2289_schaffitzel.pdf",
"country":"Deutschland",
"company":"Schaffitzel Holzindustrie GmbH & Co. KG",
"address":"DE-Schwäbisch Hall-Sulzdorf",
"link":"http:\/\/www.schaffitzel.de\/",
"link_portrait":null,
"logo":"Schaffitzel_logo_fhk.gif",
"portrait":null
},
{
"title":"Berufskraftfahrer (m\/w) ",
"date":"2015\/11\/11",
"pdf":"2290_waldkontor.pdf",
"country":"Deutschland",
"company":"claus rodenberg waldkontor gmbh",
"address":"DE-Kastorf\r\n",
"link":"http:\/\/waldkontor.com\/de\/mitarbeiter\/job.htm",
"link_portrait":null,
"logo":"waldkontor_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmermann",
"date":"2016\/1\/22",
"pdf":"2514_blaettler.pdf",
"country":"Schweiz",
"company":"Blättler Holzbau GmbH",
"address":"CH-Affeltrangen",
"link":"http:\/\/www.blaettler-holzbau.ch\/portrait_jobs.html",
"link_portrait":null,
"logo":"blaettlerholzbau_logo_fhk.gif",
"portrait":null
},
{
"title":"Betriebselektriker\/in ",
"date":"2016\/1\/12",
"pdf":"2464_hasslacher.pdf",
"country":"Österreich",
"company":"Hasslacher Preding Holzindustrie GmbH",
"address":"AT- 8504 Preding \/ Steiermark",
"link":"http:\/\/www.hasslacher.at",
"link_portrait":null,
"logo":"Hasslacher_Logo_fhk.png",
"portrait":"Hasslacher Preding Holzindustrie GmbH"
},
{
"title":"Produktionsmitarbeiter\/innen",
"date":"2015\/11\/11",
"pdf":"2294_pfeifer.pdf",
"country":"Österreich",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"AT-6460 Imst\/Tirol",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"Maschinenführer\/innen",
"date":"2015\/11\/11",
"pdf":"2294_pfeifer.pdf",
"country":"Österreich",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"AT-6460 Imst\/Tirol",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"Bautechniker\/in (Sanierung) ",
"date":"2015\/11\/19",
"pdf":"2322_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Salzburg",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Staplerfahrer\/in",
"date":"2015\/11\/11",
"pdf":"2294_pfeifer.pdf",
"country":"Österreich",
"company":"Pfeifer Holz GmbH & CO KG",
"address":"AT-6460 Imst\/Tirol",
"link":"http:\/\/www.pfeifergroup.com\/de\/karriere-bei-pfeifer\/stellenmarkt.html",
"link_portrait":null,
"logo":"Pfeifer_logo_fhk.gif",
"portrait":null
},
{
"title":"Elektrotechniker\/in (Elektro-Planung) bzw. Gebäudetechniker\/in (HKLS-Planung) ",
"date":"2016\/1\/19",
"pdf":"2490_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Bodenleger\/in (Kunststoffböden) bzw. Tischler\/in Allrounder\/in!",
"date":"2016\/1\/19",
"pdf":"2487_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-St. Pölten",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Bodenleger m\/w (AllrounderIn)",
"date":"2016\/1\/19",
"pdf":"2488_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"MaurerIn (AllrounderIn mit Kenntnissen im Trockenbau) ",
"date":"2016\/1\/19",
"pdf":"2489_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Linz",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Mitarbeiter technischer Verkauf (w\/m)",
"date":"2016\/1\/13",
"pdf":"2466_hasslacher.pdf",
"country":"Österreich",
"company":"Hasslacher Norica Stall GmbH",
"address":"AT-9832 Stall ",
"link":"http:\/\/www.hasslacher.at",
"link_portrait":null,
"logo":"Hasslacher_Logo_fhk.png",
"portrait":null
},
{
"title":"Mitarbeiter Marketing & Communications m\/w",
"date":"2016\/1\/12",
"pdf":"2465_flist.pdf",
"country":"Österreich",
"company":"F. LIST GMBH",
"address":"AT-2842 Thomasberg",
"link":"http:\/\/f-list.at\/job\/stellenangebote\/",
"link_portrait":null,
"logo":"flist_logo.png",
"portrait":null
},
{
"title":"Zimmerer (m\/w)",
"date":"2015\/11\/13",
"pdf":"2311_chiemgauer holzhaus.pdf",
"country":"Deutschland",
"company":"Chiemgauer Holzhaus",
"address":"DE-Traunstein",
"link":"http:\/\/www.chiemgauer-holzhaus.de\/",
"link_portrait":null,
"logo":"chiemgauer_holzhaus_logo_fhk.gif",
"portrait":null
},
{
"title":"Elektriker\/in (Sanierungsbranche) ",
"date":"2015\/11\/16",
"pdf":"2317_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Wien",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"GWH-InstallateurIn bzw. TrocknungstechnikerIn (Sanierungsbranche) ",
"date":"2015\/11\/16",
"pdf":"2318_job_world.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-St. Pölten",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Tischler \/ Holztechniker (w\/m)",
"date":"2016\/1\/14",
"pdf":"2319_tischlerei_kerber.pdf",
"country":"Österreich",
"company":"Tischlerei Kerber",
"address":"AT-6671 Weißenbach \/ Tirol",
"link":"http:\/\/www.tischlerei-kerber.at\/",
"link_portrait":null,
"logo":"tischlerei_kerber_logo.png",
"portrait":null
},
{
"title":"(Junior) Verkäufer - Schnittholz (m\/w)\r\nArabischer Raum, Nord- und Ostafrika",
"date":"2016\/1\/29",
"pdf":"2548_mynexxt.pdf",
"country":"Österreich",
"company":"myNEXXT",
"address":"AT-1060 Wien",
"link":"http:\/\/www.mynexxt.at\/job-for-leaders\/",
"link_portrait":null,
"logo":"mynexxt_logo_fhk.png",
"portrait":null
},
{
"title":"Ingenieure \/ Techniker (m\/w) ",
"date":"2015\/11\/20",
"pdf":"2325_pollmeier.pdf",
"country":"Deutschland",
"company":"Pollmeier Massivholz GmbH & Co. KG",
"address":"DE-Aschaffenburg",
"link":"http:\/\/www.pollmeier.com\/de\/",
"link_portrait":null,
"logo":"pollmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler (w\/m)",
"date":"2016\/2\/1",
"pdf":"2563_stuhl.pdf",
"country":"Österreich",
"company":"Längle & Hagspiel",
"address":"AT-6973 Höchst",
"link":"http:\/\/www.stuhl.at\/",
"link_portrait":null,
"logo":"LH_Logo_FHK.gif",
"portrait":null
},
{
"title":"Produktionsfacharbeiter (m\/w) für die Härterei",
"date":"2016\/2\/3",
"pdf":"2438_schmid_schrauben.pdf",
"country":"Österreich",
"company":"Schmid Schrauben Hainfeld GmbH",
"address":"AT-3170 Hainfeld",
"link":"http:\/\/www.schrauben.at\/was_wir_verbinden_haelt\/karriere",
"link_portrait":null,
"logo":"schmid_schrauben_logo_fhk.png",
"portrait":null
},
{
"title":"Verkäufer\/Technischer Berater im Aussendienst (w\/m)",
"date":"2016\/1\/13",
"pdf":"2467_ampack.pdf",
"country":"Schweiz",
"company":"Ampack AG",
"address":"CH-Rorschach",
"link":"http:\/\/www.ampack.ch",
"link_portrait":null,
"logo":"Ampack_Logo_fhk.gif",
"portrait":null
},
{
"title":"Tischler für die Handwerkstätte (m\/w)",
"date":"2016\/1\/26",
"pdf":"2531_sturm.pdf",
"country":"Österreich",
"company":"Strum GmbH",
"address":"AT-5091 Unken",
"link":"http:\/\/www.funktionstueren.eu\/",
"link_portrait":null,
"logo":"sturm_logo_fhk.png",
"portrait":null
},
{
"title":"Sachbearbeiter (m\/w) im Bereich Holzbau-Leimholz-Schnittholz-Türen",
"date":"2016\/2\/3",
"pdf":"2419_gotz.pdf",
"country":"Deutschland",
"company":"Carl Götz GmbH",
"address":"DE-Neu Ulm\r\n",
"link":"http:\/\/www.carlgoetz.de\/job\/stellenangebote.html",
"link_portrait":null,
"logo":"Goetz_logo_fhk.gif",
"portrait":null
},
{
"title":"Disponent\/in technische Einsatzplanung mit Bauerfahrung ",
"date":"2016\/1\/25",
"pdf":"2516_jobworld.pdf",
"country":"Österreich",
"company":"Job-World KG",
"address":"AT-Wien",
"link":"http:\/\/www.job-world.at",
"link_portrait":null,
"logo":"job_world_logo.png",
"portrait":null
},
{
"title":"Betriebsschlosser (m\/w)\r\n",
"date":"2015\/11\/21",
"pdf":"2337_rettenmeier.pdf",
"country":"Deutschland",
"company":"Rettenmeier Holzindustrie Wilburgstetten GmbH",
"address":"DE-Burgbernheim",
"link":"http:\/\/www.rettenmeier.com\/karriere\/offene-stellen.html",
"link_portrait":null,
"logo":"Rettenmeier_logo_fhk.gif",
"portrait":null
},
{
"title":"Dachdeckergeselle (m\/w)\r\n",
"date":"2016\/1\/13",
"pdf":"2338_ochs.pdf",
"country":"Deutschland",
"company":"OCHS GmbH",
"address":"DE-Kirchberg",
"link":"http:\/\/www.ochs.eu\/stellenangebote.html",
"link_portrait":null,
"logo":"Ochs_logo_fhk.gif",
"portrait":null
},
{
"title":"Holztechniker (m\/w)\r\n",
"date":"2015\/11\/21",
"pdf":"2339_schmid.pdf",
"country":"Deutschland",
"company":"Zimmerei & Holzbau Schmidt ",
"address":"DE-Böhlen",
"link":"http:\/\/www.zimmerei-holzbau-schmidt.de\/",
"link_portrait":null,
"logo":"Schmidt_logo_fhk.gif",
"portrait":null
},
{
"title":"Buchhalter\/in (m\/w)\r\nTeilzeit 20 Std.\/Woche",
"date":"2016\/1\/26",
"pdf":"2529_sturm.pdf",
"country":"Österreich",
"company":"Strum GmbH",
"address":"AT-5091 Unken",
"link":"http:\/\/www.funktionstueren.eu\/",
"link_portrait":null,
"logo":"sturm_logo_fhk.png",
"portrait":null
},
{
"title":"Zimmerer (m\/w)\r\n",
"date":"2015\/11\/21",
"pdf":"2342_hbh.pdf",
"country":"Deutschland",
"company":"HBH Holzbau GmbH",
"address":"DE-Landau an der Isar",
"link":"http:\/\/www.hbh-holzbau.de\/stellenangebote.html",
"link_portrait":null,
"logo":"HBH_logo_fhk.gif",
"portrait":null
},
{
"title":"Montageschreiner (m\/w)  ",
"date":"2016\/1\/13",
"pdf":"2390_Dobergo.pdf",
"country":"Deutschland",
"company":"DOBERGO GmbH & Co. KG",
"address":"DE-Loßburg-Betzweiler",
"link":"http:\/\/www.dobergo.de\/",
"link_portrait":null,
"logo":"Dobergo_logo_fhk.gif",
"portrait":null
},
{
"title":"Schreiner (m\/w) für die Endmontage im Sonderbau  ",
"date":"2016\/1\/13",
"pdf":"2391_Dobergo.pdf",
"country":"Deutschland",
"company":"DOBERGO GmbH & Co. KG",
"address":"DE-Loßburg-Betzweiler",
"link":"http:\/\/www.dobergo.de\/",
"link_portrait":null,
"logo":"Dobergo_logo_fhk.gif",
"portrait":null
},
{
"title":"Schreiner (m\/w) für den Maschinensaal  ",
"date":"2016\/1\/13",
"pdf":"2392_Dobergo.pdf",
"country":"Deutschland",
"company":"DOBERGO GmbH & Co. KG",
"address":"DE-Loßburg-Betzweiler",
"link":"http:\/\/www.dobergo.de\/",
"link_portrait":null,
"logo":"Dobergo_logo_fhk.gif",
"portrait":null
},
{
"title":"Architekt oder Hochbauzeichner (m\/w)",
"date":"2016\/1\/15",
"pdf":"2479_haering.pdf",
"country":"Schweiz",
"company":"Häring & Co. AG",
"address":"CH-Eiken",
"link":"http:\/\/www.haring.ch\/unternehmen\/unternehmen\/",
"link_portrait":null,
"logo":"Haering.gif",
"portrait":null
},
{
"title":"Monteuer (m\/w)",
"date":"2016\/1\/15",
"pdf":"2480_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Bauingenieur(m\/w) für Kundensupport",
"date":"2016\/1\/20",
"pdf":"2399_dlubal.pdf",
"country":"Deutschland",
"company":"Ingenieur-Software Dlubal GmbH",
"address":"DE-Tiefenbach",
"link":"http:\/\/www.dlubal.de\/wir-suchen.aspx",
"link_portrait":null,
"logo":"dlubal_logo_fhk.gif",
"portrait":null
},
{
"title":"Hydrauliker (m\/w)",
"date":"2016\/1\/15",
"pdf":"2481_alex_pichler.pdf",
"country":"Südtirol",
"company":"ALEX PICHLER des Pichler Alexander",
"address":"IT-39053 Karneid (BZ)",
"link":"http:\/\/www.alex-pichler.com\/",
"link_portrait":null,
"logo":"pichler_alexander_logo_fhk.png",
"portrait":null
},
{
"title":"Fachberater Architektur (w\/m)",
"date":"2016\/2\/4",
"pdf":"2581_rigips.pdf",
"country":"Österreich",
"company":"Saint-Gobain Rigips Austria GmbH",
"address":"AT-8990 Bad Aussee",
"link":"http:\/\/www.rigips.com\/",
"link_portrait":null,
"logo":"Rigips_logo_fhk.gif",
"portrait":null
},
{
"title":"Zimmerer\/-in CNC Maschinenführer Abbund",
"date":"2015\/12\/11",
"pdf":"2420_merkle.pdf",
"country":"Deutschland",
"company":"projekt holzbau merkle gmbH",
"address":"DE-Bissingen u. Teck",
"link":"http:\/\/www.projekt-holzbau.de\/",
"link_portrait":null,
"logo":"Merkle_logo_fhk.gif",
"portrait":null
},
{
"title":"Baukaufmann \/ Zimmerer \/ Techniker (m\/w)",
"date":"2015\/12\/11",
"pdf":"2421_merkle.pdf",
"country":"Deutschland",
"company":"projekt holzbau merkle gmbH",
"address":"DE-Bissingen u. Teck",
"link":"http:\/\/www.projekt-holzbau.de\/",
"link_portrait":null,
"logo":"Merkle_logo_fhk.gif",
"portrait":null
},
{
"title":"Statiker (m\/w)",
"date":"2015\/12\/11",
"pdf":"2422_finnholz.pdf",
"country":"Deutschland",
"company":"FH Finnholz Handelsgesellschaft mbH",
"address":"DE-Lien",
"link":"http:\/\/www.fh-finnholz.com\/home\/karriere.html",
"link_portrait":null,
"logo":"Finnholz_logo_fhk.gif",
"portrait":null
},
{
"title":"Konstrukteur Holztechnikr (m\/w)",
"date":"2016\/1\/25",
"pdf":"2423_neurath.pdf",
"country":"Deutschland",
"company":"König + Neurath AG",
"address":"DE-Karben",
"link":"http:\/\/www.koenig-neurath.de\/de\/unternehmen\/index.html",
"link_portrait":null,
"logo":"Koenig_Neurath_Logo_fhk.gif",
"portrait":null
},
{
"title":"Holztechniker für die CNC Programmierung (m\/w)",
"date":"2016\/1\/29",
"pdf":"2426_dula.pdf",
"country":"Deutschland",
"company":"Dula-Werke Dustmann & Co. GmbH",
"address":"DE-Dortmund",
"link":"http:\/\/www.dula.de\/",
"link_portrait":null,
"logo":"Dula_logo_fhk.gif",
"portrait":null
},
{
"title":"Techniker Holzbau m\/w",
"date":"2016\/2\/2",
"pdf":"2430_knapp.pdf",
"country":"Österreich",
"company":"Knapp GmbH",
"address":"AT-3324 Euratsfeld",
"link":"http:\/\/www.knapp-verbinder.com",
"link_portrait":null,
"logo":"Knapp_logo_fhk.gif",
"portrait":null
},
{
"title":"Technische\/r Zeichner\/in ",
"date":"2016\/2\/4",
"pdf":"2585_kranz.pdf",
"country":"Österreich",
"company":"Kranz Tischlerei GmbH. & Co.KG",
"address":"AT-4690 Schwanenstadt",
"link":"http:\/\/www.kastenfenster.at\/",
"link_portrait":null,
"logo":"kranz_logo_fhk.png",
"portrait":null
},
{
"title":"Außendienstmitarbeiter m\/w \r\nDeutschland West",
"date":"2016\/2\/2",
"pdf":"2532_knapp_verbinder.pdf",
"country":"Deutschland",
"company":"Knapp GmbH",
"address":"DE-85591 Vaterstetten",
"link":"http:\/\/www.knapp-verbinder.com\/",
"link_portrait":null,
"logo":"Knapp_logo_fhk.gif",
"portrait":null
},
{
"title":"Zeichner\/Konstrukteur in IMOS (m\/w), 100%\r\n",
"date":"2016\/1\/15",
"pdf":"2433_alpnachnorm.pdf",
"country":"Schweiz",
"company":"Alpnach Norm-Schrankelemente AG",
"address":"CH-Alpnach Dorf",
"link":"http:\/\/www.alpnachnorm.ch\/unternehmen\/aktuelles\/job.html",
"link_portrait":null,
"logo":"Alpnach_Logo_fhk.gif",
"portrait":null
},
{
"title":"Zeichner \/ Konstrukteur Holzbau (m\/w)\r\n",
"date":"2016\/1\/21",
"pdf":"2434_strabag.pdf",
"country":"Schweiz",
"company":"STRABAG AG",
"address":"CH-Schlieren",
"link":"http:\/\/karriere.strabag.com\/KARRIEREWEBSITE\/",
"link_portrait":null,
"logo":"strabag_logo_fhk.png",
"portrait":null
}
]';


    $data = json_decode($jsonData);

    foreach($data as $job) {
      $fileName = Yii::$app->basePath . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . $this->normalizeString($job->title.'_'.$job->company) . ".json";
      $job->type = "Fachkräfte";
      file_put_contents($fileName, \Underscore\Parse::toJSON($job));
    }

  }

  public static function normalizeString ($str = '')
  {
    $str = strip_tags($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    $str = preg_replace('/[\"\*\/\:\<\>\?\'\|]+/', ' ', $str);
    $str = strtolower($str);
    $str = html_entity_decode( $str, ENT_QUOTES, "utf-8" );
    $str = htmlentities($str, ENT_QUOTES, "utf-8");
    $str = preg_replace("/(&)([a-z])([a-z]+;)/i", '$2', $str);
    $str = str_replace(' ', '-', $str);
    $str = rawurlencode($str);
    $str = str_replace('%', '-', $str);
    $str = trim($str, "-");
    $str = str_replace('--', '-', $str);
    return $str;
  }
}
