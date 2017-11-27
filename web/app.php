<?php

require __DIR__.'/../vendor/autoload.php';

use Silex\Application as SilexApplication;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\ProcessBuilder;
use \Symfony\Component\Process\Exception\RuntimeException;


$app = new SilexApplication();
//$app['debug'] = getenv('DEBUG') == 1;

$app->get('/', function(Request $request) {
    return <<<PHPEOL
<html>
<body>
    <form action="" method="POST" enctype="multipart/form-data">
        <input type="file" name="file" id="">
        <button>send</button>
    </form>
</body>
</html>
PHPEOL;
});

$app->match('/', function(SilexApplication $app, Request $request) {
    if ($request->files->count() == 0) {
        $app->abort(400, 'No files found');
    }
    if ($request->files->count() > 1) {
        $app->abort(400, 'Supported only one file');
    }
    /** @var UploadedFile $file */
    $file = current($request->files->all());

    $tmpName = uniqid('document');
    $tmpFileName = $tmpName.'.'.$file->getClientOriginalExtension();
    $workDirName = sys_get_temp_dir();
    $file->move($workDirName, $tmpFileName);

    $inputFileName = $workDirName.'/'.$tmpFileName;
    $outputFileName = $workDirName.'/'.$tmpName.'.pdf';

    $finder = new ExecutableFinder();

    $sofficeBinnary = $finder->find('soffice');
    $processWaitTime = intval(getenv('PROCESS_WAIT_TIME'));
    if (!$processWaitTime) {
        $processWaitTime = 10;
    }

    $arguments = [$sofficeBinnary];

    $arguments = array_merge($arguments, [
        '--headless',
        '--convert-to',
        'pdf:writer_pdf_Export',
        '--outdir',
        $workDirName,
        $inputFileName
    ]);

    register_shutdown_function(function() use ($inputFileName, $outputFileName){
        @unlink($inputFileName);
        @unlink($outputFileName);
    });

    $process = ProcessBuilder::create($arguments)->getProcess();
    $process->setTimeout($processWaitTime);

    try {
        $process->mustRun();
    } catch (ProcessTimedOutException $e) {
        $app->abort(422, 'Process timeout');
    } catch (RuntimeException $e) {
        $app->abort(500, 'ERROR: '.$e->getMessage());
    }

    if (!file_exists($outputFileName)) {
        $app->abort(400, 'Convert failed');
    }

    return $app->sendFile($outputFileName);

})->method('POST|PUT');

$app->error(function(\Exception $e) {
    return $e->getMessage();
});

$app->run();