<?php

namespace FS\SolrBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Reference;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class FSSolrExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
    	$loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
    	$loader->load('services.xml');    	
    	
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->setupConnections($config, $container);
        
        $container->getDefinition('solr.meta.information.factory')->addMethodCall(
        	'setDoctrineConfiguration',
        	array(new Reference(sprintf('doctrine.orm.%s_configuration', $config['entity_manager'])))
        );
    }
    
    private function setupConnections(array $config, ContainerBuilder $container) {
    	$connectionParameters = $config['solr'];
    	
    	if (count($config['solr']['path']) > 0) {
    		$defaultPath = reset($config['solr']['path']);
    		
    		$connectionParameters['path'] = $defaultPath;
    	}
    	
    	$container->getDefinition('solr.connection')->setArguments(array($connectionParameters));
    }
    
}
