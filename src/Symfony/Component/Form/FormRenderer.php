<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form;

use Symfony\Component\Form\Extension\Core\View\ChoiceView;
use Symfony\Component\Form\Exception\FormException;
use Symfony\Component\Form\Extension\Csrf\CsrfProvider\CsrfProviderInterface;

/**
 * Renders a form into HTML using a rendering engine.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class FormRenderer implements FormRendererInterface
{
    /**
     * @var FormRendererEngineInterface
     */
    private $engine;

    /**
     * @var CsrfProviderInterface
     */
    private $csrfProvider;

    /**
     * @var array
     */
    private $blockNameHierarchyMap = array();

    /**
     * @var array
     */
    private $hierarchyLevelMap = array();

    /**
     * @var array
     */
    private $variableMap = array();

    /**
     * @var array
     */
    private $variableStack = array();

    public function __construct(FormRendererEngineInterface $engine, CsrfProviderInterface $csrfProvider = null)
    {
        $this->engine = $engine;
        $this->csrfProvider = $csrfProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function getEngine()
    {
        return $this->engine;
    }

    /**
     * {@inheritdoc}
     */
    public function setTheme(FormView $view, $themes)
    {
        $this->engine->setTheme($view, $themes);
    }

    /**
     * {@inheritdoc}
     */
    public function renderCsrfToken($intention)
    {
        if (null === $this->csrfProvider) {
            throw new \BadMethodCallException('CSRF token can only be generated if a CsrfProviderInterface is injected in the constructor.');
        }

        return $this->csrfProvider->generateCsrfToken($intention);
    }

    /**
     * {@inheritdoc}
     */
    public function renderBlock(FormView $view, $blockName, array $variables = array())
    {
        if (0 == count($this->variableStack)) {
            throw new FormException('This method should only be called while rendering a form element.');
        }

        $scopeVariables = end($this->variableStack);

        $resource = $this->engine->getResourceForBlockName($view, $blockName);

        if (!$resource) {
            throw new FormException(sprintf('No block "%s" found while rendering the form.', $blockName));
        }

        // Merge the passed with the existing attributes
        if (isset($variables['attr']) && isset($scopeVariables['attr'])) {
            $variables['attr'] = array_replace($scopeVariables['attr'], $variables['attr']);
        }

        // Merge the passed with the exist *label* attributes
        if (isset($variables['label_attr']) && isset($scopeVariables['label_attr'])) {
            $variables['label_attr'] = array_replace($scopeVariables['label_attr'], $variables['label_attr']);
        }

        // Do not use array_replace_recursive(), otherwise array variables
        // cannot be overwritten
        $variables = array_replace($scopeVariables, $variables);

        $this->variableStack[] = $variables;

        // Do the rendering
        $html = $this->engine->renderBlock($view, $resource, $blockName, $variables);

        // Clear the stack
        array_pop($this->variableStack);

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function searchAndRenderBlock(FormView $view, $blockNameSuffix, array $variables = array())
    {
        $renderOnlyOnce = in_array($blockNameSuffix, array('row', 'widget'));

        if ($renderOnlyOnce && $view->isRendered()) {
            return '';
        }

        // The cache key for storing the variables and types
        $mapKey = $uniqueBlockName = $view->vars['full_block_name'] . '_' . $blockNameSuffix;

        // In templates, we have to deal with two kinds of block hierarchies:
        //
        //   +---------+          +---------+
        //   | Theme B | -------> | Theme A |
        //   +---------+          +---------+
        //
        //   form_widget -------> form_widget
        //       ^
        //       |
        //  choice_widget -----> choice_widget
        //
        // The first kind of hierarchy is the theme hierarchy. This allows to
        // override the block "choice_widget" from Theme A in the extending
        // Theme B. This kind of inheritance needs to be supported by the
        // template engine and, for example, offers "parent()" or similar
        // functions to fall back from the custom to the parent implementation.
        //
        // The second kind of hierarchy is the form type hierarchy. This allows
        // to implement a custom "choice_widget" block (no matter in which theme),
        // or to fallback to the block of the parent type, which would be
        // "form_widget" in this example (again, no matter in which theme).
        // If the designer wants to explicitly fallback to "form_widget" in his
        // custom "choice_widget", for example because he only wants to wrap
        // a <div> around the original implementation, he can simply call the
        // widget() function again to render the block for the parent type.
        //
        // The second kind is implemented in the following blocks.
        if (!isset($this->blockNameHierarchyMap[$mapKey])) {
            // INITIAL CALL
            // Calculate the hierarchy of template blocks and start on
            // the bottom level of the hierarchy (= "_<id>_<section>" block)
            $blockNameHierarchy = array();
            foreach ($view->vars['types'] as $type) {
                $blockNameHierarchy[] = $type . '_' . $blockNameSuffix;
            }
            $blockNameHierarchy[] = $uniqueBlockName;
            $hierarchyLevel = count($blockNameHierarchy) - 1;

            // The default variable scope contains all view variables, merged with
            // the variables passed explicitly to the helper
            $scopeVariables = $view->vars;
        } else {
            // RECURSIVE CALL
            // If a block recursively calls renderSection() again, resume rendering
            // using the parent type in the hierarchy.
            $blockNameHierarchy = $this->blockNameHierarchyMap[$mapKey];
            $hierarchyLevel = $this->hierarchyLevelMap[$mapKey] - 1;

            // Reuse the current scope and merge it with the explicitly passed variables
            $scopeVariables = $this->variableMap[$mapKey];
        }

        // Load the resource where this block can be found
        $resource = $this->engine->getResourceForBlockNameHierarchy($view, $blockNameHierarchy, $hierarchyLevel);

        // Update the current hierarchy level to the one at which the resource was
        // found. For example, if looking for "choice_widget", but only a resource
        // is found for its parent "form_widget", then the level is updated here
        // to the parent level.
        $hierarchyLevel = $this->engine->getResourceHierarchyLevel($view, $blockNameHierarchy, $hierarchyLevel);

        // The actually existing block name in $resource
        $blockName = $blockNameHierarchy[$hierarchyLevel];

        // Escape if no resource exists for this block
        if (!$resource) {
            throw new FormException(sprintf(
                'Unable to render the form as none of the following blocks exist: "%s".',
                implode('", "', array_reverse($blockNameHierarchy))
            ));
        }

        // Merge the passed with the existing attributes
        if (isset($variables['attr']) && isset($scopeVariables['attr'])) {
            $variables['attr'] = array_replace($scopeVariables['attr'], $variables['attr']);
        }

        // Merge the passed with the exist *label* attributes
        if (isset($variables['label_attr']) && isset($scopeVariables['label_attr'])) {
            $variables['label_attr'] = array_replace($scopeVariables['label_attr'], $variables['label_attr']);
        }

        // Do not use array_replace_recursive(), otherwise array variables
        // cannot be overwritten
        $variables = array_replace($scopeVariables, $variables);

        // In order to make recursive calls possible, we need to store the block hierarchy,
        // the current level of the hierarchy and the variables so that this method can
        // resume rendering one level higher of the hierarchy when it is called recursively.
        //
        // We need to store these values in maps (associative arrays) because within a
        // call to widget() another call to widget() can be made, but for a different view
        // object. These nested calls should not override each other.
        $this->blockNameHierarchyMap[$mapKey] = $blockNameHierarchy;
        $this->hierarchyLevelMap[$mapKey] = $hierarchyLevel;
        $this->variableMap[$mapKey] = $variables;

        // We also need to store the view and the variables so that we can render custom
        // blocks with renderBlock() using the same themes and variables as in the outer
        // block.
        //
        // A stack is sufficient for this purpose, because renderBlock() always accesses
        // the immediate next outer scope, which is always stored at the end of the stack.
        $this->variableStack[] = $variables;

        // Do the rendering
        $html = $this->engine->renderBlock($view, $resource, $blockName, $variables);

        // Clear the stack
        array_pop($this->variableStack);

        // Clear the maps
        unset($this->blockNameHierarchyMap[$mapKey]);
        unset($this->hierarchyLevelMap[$mapKey]);
        unset($this->variableMap[$mapKey]);

        if ($renderOnlyOnce) {
            $view->setRendered();
        }

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function humanize($text)
    {
        return ucfirst(trim(strtolower(preg_replace('/[_\s]+/', ' ', $text))));
    }
}
