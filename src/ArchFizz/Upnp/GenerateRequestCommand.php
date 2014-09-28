<?php

namespace ArchFizz\Upnp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class GenerateRequestCommand extends Command
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    protected function configure()
    {
        $this->setName('generate:request');

        $this->addArgument('xml', InputArgument::OPTIONAL, 'The URL to the XML file');

        $this->setDescription(<<<EOL
UPnP request generator, v0.1.

Usage: php upnp <URL_TO_DESCRIPTION_XML_FILE>

This tool will generate a series of directories and files corresponding
to devices, services, and actions exposed by a UPnP daemon. This tool
does not perform discovery of UPnP daemons. The author recommends the
nmap NSE scripts 'broadcast-upnp-info' and 'upnp-info' for UPnP daemon
discovery.

Requests, as generated, have each variable pre-filled with the type of
variable value expected by the UPnP endpoint. Modify generated request
files before use, or load requests into a tool such as Burp Repeater in
order to modify variables to useful values before exercising control
over UPnP daemons.
EOL
);
    }


    /**
     * @todo refactor to classes
     *
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Attempting to retrieve descriptor XML file...");

        $xmlUrl = $input->getArgument('xml');

        $xml = $this->downloadXml($xmlUrl);

        // Register namespace so xpath works
        $deviceNamespace = implode($xml->getDocNamespaces());
        $xml->registerXPathNamespace('upnp', $deviceNamespace);

        $host = explode('/', $xmlUrl);
        $hostname = $host[2];

        $this->getFilesystem()->mkdir($hostname);

        foreach ($xml->xpath('//upnp:device') as $device) {
            $deviceName = $device->deviceType;
            $device->registerXPathNamespace('upnp', $deviceNamespace);

            $output->writeln(sprintf("Starting work on UPnP device %s", $deviceName));

            $this->getFilesystem()->mkdir(sprintf('%s/%s', $hostname, $deviceName));

            foreach ($device->serviceList->service as $service) {
                $serviceId = $service->serviceType;
                $serviceControlUrl = ltrim($service->controlURL, '/');

                $output->writeln(sprintf("Attempting to retrieve service description for %s", $serviceId));

                if ('http' === substr($service->SCPDURL, 0, 4)) {
                    $serviceDescription = $this->downloadXml($service->SCPDURL);
                } elseif ('/' === substr($service->SCPDURL, 0, 1)) {
                    $serviceDescription = $this->downloadXml("http://" . $hostname . $service->SCPDURL);
                } else {
                    $serviceDescription = $this->downloadXml(implode('/', array_slice($host, 0, -1)) . "/" . ltrim($service->SCPDURL, "/"));
                }

                if (!$serviceDescription) {
                    $output->writeln(sprintf("Couldn't retrieve description xml file for %s", $serviceId));
                    continue;
                }

                $serviceNamespace = implode($serviceDescription->getDocNamespaces());
                $serviceDescription->registerXPathNamespace('upnp', $serviceNamespace);
                $this->getFilesystem()->mkdir(sprintf('%s/%s/%s', $hostname, $deviceName, $serviceId));

                $output->writeln(sprintf("Generating actions for service %s", $serviceId));

                foreach ($serviceDescription->xpath('//upnp:action') as $action) {
                    $actionName = $action->name;
                    $output->writeln($actionName);
                    $actionBody = <<<XML
<?xml version=\"1.0\"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <u:$actionName xmlns:u="$serviceId">
XML;
                foreach ($action->argumentList->argument as $argument) {
                    $serviceDescription->registerXPathNamespace('upnp', $serviceNamespace);
                    if ('in' === $argument->direction) {
                        $actionBody .= "         <".$argument->name.">";
                        $stateVariable = $serviceDescription->xpath("//upnp:stateVariable[upnp:name='$argument->relatedStateVariable']");
                        $actionBody .= $stateVariable[0]->dataType;
                        $actionBody .= "</".$argument->name.">\n";
                    }
                }
                $actionBody .= <<<XML
        </u:$actionName>
    </s:Body>
</s:Envelope>
XML;
                    $pathToResponseFile = "$hostname/$deviceName/$serviceId/$actionName";

                    $requestHeaders = "POST /$serviceControlUrl HTTP/1.1\n" .
                        "Host: $hostname\n" .
                        "SOAPAction: \"$serviceId#$actionName\"\n" .
                        "Content-Type: text/xml; charset=\"utf-8\"\n" .
                        "Content-Length: " . strlen($actionBody) . "\n" .
                        "\n" .
                        $actionBody;

                    $this->getFilesystem()->dumpFile($pathToResponseFile, $requestHeaders);
              }
           }
        }
    }

    private function downloadXml($url)
    {
       $context = stream_context_create(array('http' => array('timeout' => 15)));

       $data = file_get_contents($url, false, $context);

       if (!$data) {
          throw new \Exception('Can\'t retrieve descriptor XML file: ' . $url);
       }

       return simplexml_load_string($data);
    }

    /**
     * @return Filesystem
     */
    private function getFilesystem()
    {
        if (!$this->filesystem) {
            $this->filesystem = new Filesystem();
        }

        return $this->filesystem;
    }
}
