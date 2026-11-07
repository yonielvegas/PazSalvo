<?php
require __DIR__.'/../../vendor/autoload.php';
$out=['http_foundation'=>Composer\InstalledVersions::getPrettyVersion('symfony/http-foundation'),'mime'=>Composer\InstalledVersions::getPrettyVersion('symfony/mime')];
// Bounded in-process parse: no HTTP requests, no load test, no persisted changes.
foreach([4,12,17] as $spaces){$value='x='.str_repeat('a',7900).str_repeat(' ',$spaces).', y=1';$start=hrtime(true);new Symfony\Component\HttpFoundation\HeaderBag(['Cache-Control'=>$value]);$out['header_parse_ms'][(string)$spaces]=round((hrtime(true)-$start)/1e6,3);}
$h=new Symfony\Component\Mime\Header\ParameterizedHeader('Content-Disposition','attachment',["fixture\r\nX-Audit-Fixture"=>'value']);$out['mime_parameter_accepts_crlf']=str_contains($h->toString(),"\r\nX-Audit-Fixture");
echo json_encode($out,JSON_PRETTY_PRINT).PHP_EOL;
