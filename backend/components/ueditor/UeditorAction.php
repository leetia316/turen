<?php

namespace backend\components\ueditor;

use Yii;
use yii\base\Action;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\Json;

class UeditorAction extends Action
{
    /**
     * @var array
     */
    public $config = [];

    public function init()
    {
        //close csrf
        Yii::$app->getRequest()->enableCsrfValidation = false;
        
        //默认设置
        $_config = require(__DIR__ . '/config.php');
        //load config file
        $this->config = ArrayHelper::merge($_config, $this->config);
        
        parent::init();
    }

    /**
     * 执行并处理action
     */
    public function run()
    {
        $action = Yii::$app->request->get('action');
        switch ($action) {
            case 'config'://加载编辑器时，首次获取后台的配置
                $result = Json::encode($this->config);
                break;

            	/* 上传图片 */
            case 'uploadimage':
                /* 上传涂鸦 */
            case 'uploadscrawl':
                /* 上传视频 */
            case 'uploadvideo':
                /* 上传文件 */
            case 'uploadfile':
                $result = $this->upload();
                break;

            	/* 列出图片 */
            case 'listimage':
                /* 列出文件 */
            case 'listfile':
                $result = $this->fileList();
                break;

            	/* 抓取远程文件 */
            case 'catchimage':
                $result = $this->remoteSave();
                break;

            default:
                $result = Json::encode([
                    'state' => Yii::t('ueditor', 'Request the address wrong')
                ]);
                break;
        }
        /* 输出结果 */
        $callback = Yii::$app->getRequest()->get('callback');
        if ($callback) {
            if (preg_match('/^[\w_]+$/', $callback)) {
                echo $callback . '(' . $result . ')';
            } else {
                echo Json::encode([
                    'state' => Yii::t('ueditor', 'Callback parameter is not valid'),
                ]);
            }
        } else {
            echo $result;
        }
    }

    /**
     * 上传
     * @return string
     */
    protected function upload()
    {
        $mode = 'upload';
    	switch (Yii::$app->getRequest()->get('action')) {
            case 'uploadimage':
                $config = array(
                    'pathFormat' => $this->config['imagePathFormat'],
                    'maxSize' => $this->config['imageMaxSize'],
                    'allowFiles' => $this->config['imageAllowFiles']
                );
                $fieldName = $this->config['imageFieldName'];
                break;
            case 'uploadscrawl':
                $config = array(
                    'pathFormat' => $this->config['scrawlPathFormat'],
                    'maxSize' => $this->config['scrawlMaxSize'],
                    'allowFiles' => $this->config['scrawlAllowFiles'],
                    'oriName' => 'scrawl.png'
                );
                $fieldName = $this->config['scrawlFieldName'];
                $mode = 'base64';
                break;
            case 'uploadvideo':
                $config = array(
                    'pathFormat' => $this->config['videoPathFormat'],
                    'maxSize' => $this->config['videoMaxSize'],
                    'allowFiles' => $this->config['videoAllowFiles']
                );
                $fieldName = $this->config['videoFieldName'];
                break;
            case 'uploadfile':
            default:
                $config = array(
                    'pathFormat' => $this->config['filePathFormat'],
                    'maxSize' => $this->config['fileMaxSize'],
                    'allowFiles' => $this->config['fileAllowFiles']
                );
                $fieldName = $this->config['fileFieldName'];
                break;
        }
        
        //开始上传
        $uploader = new Uploader($fieldName, $config, $mode);
        //返回数据
        return Json::encode($uploader->getFileInfo());
        /**
         * 得到上传文件所对应的各个参数,数组结构
         * array(
         *     'state' => '',          //上传状态，上传成功时必须返回'SUCCESS'
         *     'url' => '',            //返回的地址
         *     'title' => '',          //新文件名
         *     'original' => '',       //原始文件名
         *     'type' => ''            //文件类型
         *     'size' => '',           //文件大小
         * )
         */
    }

    /**
     * 获取已上传的文件列表
     * @return string
     */
    protected function fileList()
    {
        /* 判断类型 */
        switch (Yii::$app->getRequest()->get('action')) {
            /* 列出文件 */
            case 'listfile':
                $allowFiles = $this->config['fileManagerAllowFiles'];
                $listSize = $this->config['fileManagerListSize'];
                $path = $this->config['fileManagerListPath'];
                break;
            /* 列出图片 */
            case 'listimage':
            default:
                $allowFiles = $this->config['imageManagerAllowFiles'];
                $listSize = $this->config['imageManagerListSize'];
                $path = $this->config['imageManagerListPath'];
        }
        $allowFiles = substr(str_replace('.', '|', join('', $allowFiles)), 1);

        /* 获取参数 */
        $size = Yii::$app->getRequest()->get('size', $listSize);
        $start = Yii::$app->getRequest()->get('start', 0);
        $end = (int)$start + (int)$size;

        /* 获取文件列表 */
        $path = FileHelper::normalizePath(Yii::getAlias('@backend/web/upload/image/'));
        $files = $this->getfiles($path, $allowFiles);
        
        //如果没有找到文件
        if (empty($files)) {
            return Json::encode([
                'state' => Yii::t('ueditor', 'No match file'),
                'list' => [],
                'start' => $start,
                'total' => count($files)
            ]);
        }

        /* 获取指定范围的列表 */
        $len = count($files);
        for ($i = min($end, $len) - 1, $list = []; $i < $len && $i >= 0 && $i >= $start; $i--) {
            $list[] = $files[$i];
        }

        /* 返回数据 */
        $result = Json::encode([
            'state' => 'SUCCESS',
            'list' => $list,
            'start' => $start,
            'total' => count($files)
        ]);

        return $result;
    }

    /**
     * 抓取远程图片
     * @return string
     */
    protected function remoteSave()
    {
        /* 上传配置 */
        $config = [
            'pathFormat' => $this->config['catcherPathFormat'],
            'maxSize' => $this->config['catcherMaxSize'],
            'allowFiles' => $this->config['catcherAllowFiles'],
            'oriName' => 'remote.png'
        ];
        $fieldName = $this->config['catcherFieldName'];

        /* 抓取远程图片 */
        $list = [];
        if (Yii::$app->getRequest()->post($fieldName)) {
            $source = Yii::$app->getRequest()->post($fieldName);
        } else {
            $source = Yii::$app->getRequest()->get($fieldName);
        }
        
        foreach ($source as $imgUrl) {
            $uploader = new Uploader($imgUrl, $config, 'remote');
            $info = $uploader->getFileInfo();
            
            if($info['state'] == 'SUCCESS') {
            	array_push($list, [
            			'state' => $info['state'],
            			'url' => $info['url'],
            			'size' => $info['size'],
            			'title' => htmlspecialchars($info['title']),
            			'original' => htmlspecialchars($info['original']),
            			'source' => htmlspecialchars($imgUrl)
            	]);
            }
        }

        /* 返回抓取数据 */
        return Json::encode([
            'state' => count($list) ? 'SUCCESS' : 'ERROR',
            'list' => $list
        ]);
    }

    /**
     * 遍历获取目录下的指定类型的文件
     * @param $path
     * @param $allowFiles
     * @param array $files
     * @return array|null
     */
    protected function getfiles($path, $allowFiles, &$files = [])
    {
        if (!is_dir($path)) return null;
        if (substr($path, strlen($path) - 1) != '/') $path .= '/';
        
        $handle = opendir($path);
        while (false !== ($file = readdir($handle))) {
            if ($file != '.' && $file != '..') {
                $path2 = $path . $file;
                if (is_dir($path2)) {
                    $this->getfiles($path2, $allowFiles, $files);
                } else {
                    if (preg_match('/\.(' . $allowFiles . ')$/i', $file)) {
                        $files[] = [
                            'url' => Yii::getAlias('@web').'/'.str_replace('\\', '/', substr($path2, strlen(Yii::getAlias('@backend/web/')))),
                            'mtime' => filemtime($path2)
                        ];
                    }
                }
            }
        }
        return $files;
    }
}