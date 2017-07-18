<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;
use yii\helpers\Json;


class ExportController extends CrelishBaseController
{
    public $layout = 'crelish.twig';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['login'],
                        'roles' => ['?'],
                    ],
                    [
                        'allow' => true,
                        'actions' => [],
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }


    public function init()
    {
        parent::init();
    }

    public function actionIndex()
    {
        $elements = new CrelishJsonDataProvider('elements', [
            'key' => 'key',
            'sort' => ['by' => ['label', 'asc']],
            'limit' => 99
        ]);

        $types = array();

        foreach ($elements->all()['models'] as $element) {
            if (isset($element['export'])) {
                $types[] = $element;
            }
        }

        return $this->render('index.twig',['types'=>$types]);
    }


    public function actionFilter() {
        $options = array();
        $start = strtotime('2017-07-03 00:00:00');
        $tstamp = $start;
        while ($tstamp < time()) {
            $options[date('W',$tstamp)] = 'KW' .date('W',$tstamp) . ' ('.date('d.m.Y',$tstamp).' - '.date('d.m.Y',$tstamp+6*24*60*60).')';
            $tstamp += (7*24*60*60);
        }
        $options = array_reverse($options,true);
        return $this->render('weeklyfilter.twig',['options'=>$options,'type'=>$_GET['type']]);
    }

    public function actionRun() {

        //clear cache
        \Yii::$app->cache->flush();

        // Fetch data types.
        $elements = new CrelishJsonDataProvider('elements', [
            'key' => 'key',
            'sort' => ['by' => ['label', 'asc']],
            'limit' => 99
        ]);

        $importItems = $elements->all()['models'];

        foreach ($importItems as $item) {
            // Build cache for each.
            $dataCache = new CrelishJsonDataProvider($item['key']);
            $tmp = $dataCache->rawAll();
        }

        //end clear cache



        $filter = ['created'=>['between',strtotime(date('Y') . "W" . $_GET['week']),strtotime(date('Y') . "W" . ($_GET['week']+1))-1]];


        $modelProvider = new CrelishJsonDataProvider('enrollmentform', ['filter' => $filter,'limit'=>10000,'sort'=>['by'=>['created','asc']]], NULL);

        $all = $modelProvider->all()['models'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "Name der KITA");
        $sheet->setCellValue('B1', "Strasse / Nr.");
        $sheet->setCellValue('C1', "PLZ");
        $sheet->setCellValue('D1', "Ort");
        $sheet->setCellValue('E1', "Vorname und Nachname der KITA-Leitung");
        $sheet->setCellValue('F1', "Telefonnummer (tagsüber)");
        $sheet->setCellValue('G1', "E-Mail-Adresse");
        $sheet->setCellValue('H1', "Anzahl Kinder");
        $sheet->setCellValue('I1', "Ruhestunde von");
        $sheet->setCellValue('J1', "Ruhestunde bis");
        $sheet->setCellValue('K1', "Kita ist");
        $sheet->setCellValue('L1', "Parkmöglichkeiten in der Nähe?");
        $sheet->setCellValue('M1', "Baujahr der KITA");
        $sheet->setCellValue('N1', "Ungefäre m² Anzahl");
        $sheet->setCellValue('O1', "Ist ein Garten vorhanden?");
        $sheet->setCellValue('P1', "Die Verschönerung ist in KW 41 möglich");
        $sheet->setCellValue('Q1', "Ihre Wünsche für die Verschönerung");
        $sheet->setCellValue('R1', "Bilder");
        $sheet->setCellValue('S1', "Angemeldet am");

        for ($i=65;$i<83;$i++) {
            $sheet->getCell(chr($i) . '1')->getStyle()->getFont()->setBold(true);
        }

        $row = 2;
        foreach ($all as $model) {
            $sheet->setCellValue('A'.$row, $model['name']);
            $sheet->setCellValue('B'.$row, $model['street']);
            $sheet->setCellValue('C'.$row, $model['zip']);
            $sheet->setCellValue('D'.$row, $model['city']);
            $sheet->setCellValue('E'.$row, $model['name_kita_boss']);
            $sheet->setCellValue('F'.$row, $model['phone']);
            $sheet->setCellValue('G'.$row, $model['mail']);
            $sheet->setCellValue('H'.$row, $model['kidscount']);
            $sheet->setCellValue('I'.$row, $model['hour_of_rest']);
            $sheet->setCellValue('J'.$row, $model['hour_of_rest_end']);
            $sheet->setCellValue('K'.$row, $model['stories']);
            $sheet->setCellValue('L'.$row, $model['parkingspaces']);
            $sheet->setCellValue('M'.$row, $model['year_of_construction']);
            $sheet->setCellValue('N'.$row, $model['area']);
            $sheet->setCellValue('O'.$row, $model['garden']);
            $sheet->setCellValue('P'.$row, $model['kw41']);
            $sheet->setCellValue('Q'.$row, $model['message']);
            $tmp = explode("_",$model['images']);
            $sheet->setCellValue('R'.$row,'download');
            $sheet->getCell('R'.$row)->getHyperlink()->setUrl('https://kita.bauundhobby.ch/site/download.html?id=' . array_shift($tmp));
            $sheet->getCell('R'.$row)->getStyle()->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLUE))->setUnderline(true);
            $sheet->setCellValue('S'.$row,date('d.m.Y H:i:s',$model['created']));
            $row++;
        }


        // redirect output to client browser
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="kita_export_kw' . $_GET['week'] . '.xlsx"');
        header('Cache-Control: max-age=0');


        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        die();

    }
}
