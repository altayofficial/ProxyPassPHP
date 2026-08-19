<?php

declare(strict_types=1);

if(ini_get("phar.readonly") === "1"){
	fwrite(STDERR, "phar.readonly must be disabled, run this script with -d phar.readonly=0" . PHP_EOL);
	exit(1);
}

$baseDir = dirname(__DIR__);
$output = $argv[1] ?? $baseDir . "/ProxyPass.phar";

if(!is_file($baseDir . "/vendor/autoload.php")){
	fwrite(STDERR, "Dependencies are not installed, run composer install --no-dev first" . PHP_EOL);
	exit(1);
}

$excluded = "@/(tests?|docs?|examples?|\.github|\.git)/@i";

if(is_file($output)){
	Phar::unlinkArchive($output);
}

$phar = new Phar($output);
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub('<?php require "phar://" . __FILE__ . "/bin/proxypass.php"; __HALT_COMPILER();');
$phar->startBuffering();

$files = 0;
foreach(["bin", "resources", "src", "vendor"] as $directory){
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir . "/" . $directory, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		/** @var SplFileInfo $file */
		$path = str_replace("\\", "/", substr($file->getPathname(), strlen($baseDir) + 1));
		if(!$file->isFile() || preg_match($excluded, "/" . $path) === 1){
			continue;
		}
		$phar->addFile($file->getPathname(), $path);
		$files++;
	}
}

$phar->addFile($baseDir . "/LICENSE", "LICENSE");
$phar->addFile($baseDir . "/README.md", "README.md");
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

printf("Wrote %s (%d files, %.2f MiB)%s", $output, $files + 2, filesize($output) / 1024 / 1024, PHP_EOL);
