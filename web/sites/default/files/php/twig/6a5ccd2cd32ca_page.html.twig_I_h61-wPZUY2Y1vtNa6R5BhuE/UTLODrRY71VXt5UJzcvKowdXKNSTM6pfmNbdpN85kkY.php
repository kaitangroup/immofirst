<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedTestError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* themes/contrib/gin/templates/page/page.html.twig */
class __TwigTemplate_5135a450a7dbaac0ce0e358ab423832b extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 42
        $context["page_title_block"] = (($context["active_admin_theme"] ?? null) . "_page_title");
        // line 43
        $context["local_actions_block"] = (($context["active_admin_theme"] ?? null) . "_local_actions");
        // line 44
        yield "
";
        // line 45
        if ((($tmp = ($context["active_core_navigation"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 46
            yield "  <header class=\"region gin--navigation-top-bar--offset ";
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["gin_form_actions_class"] ?? null), "html", null, true);
            yield "\">
    <div class=\"layout-container region-sticky__items\">
      <div class=\"region-sticky__items__inner\">
        ";
            // line 49
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($_v0 = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 49)) && is_array($_v0) || $_v0 instanceof ArrayAccess && in_array($_v0::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v0[(($_v1 = ($context["page_title_block"] ?? null)) instanceof \Stringable ? (string) $_v1 : $_v1)] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 49), ($context["page_title_block"] ?? null), [], "array", false, false, true, 49)), "html", null, true);
            yield "
        ";
            // line 50
            if ( !(($tmp = ($context["active_core_navigation"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 51
                yield "          ";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($_v2 = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 51)) && is_array($_v2) || $_v2 instanceof ArrayAccess && in_array($_v2::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v2[(($_v3 = ($context["local_actions_block"] ?? null)) instanceof \Stringable ? (string) $_v3 : $_v3)] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 51), ($context["local_actions_block"] ?? null), [], "array", false, false, true, 51)), "html", null, true);
                yield "
        ";
            }
            // line 53
            yield "        ";
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["gin_form_actions"] ?? null), "html", null, true);
            yield "
      </div>
    </div>
  </header>
";
        } else {
            // line 58
            yield "  <div class=\"gin-secondary-toolbar layout-container\">
    <div class=\"gin-breadcrumb-wrapper\">
    ";
            // line 60
            if ((($context["route_name"] ?? null) == "entity.node.canonical")) {
                // line 61
                yield "      <div class=\"region region-breadcrumb gin-region-breadcrumb\">
        <nav class=\"breadcrumb\" role=\"navigation\" aria-labelledby=\"system-breadcrumb\">
          <h2 id=\"system-breadcrumb\" class=\"visually-hidden\">";
                // line 63
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Breadcrumb"));
                yield "</h2>
          <ol class=\"breadcrumb__list\">
            <li class=\"breadcrumb__item\">
              ";
                // line 66
                if (((($tmp = ($context["entity_edit_url"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp) && (($tmp = ($context["entity_title"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp))) {
                    // line 67
                    yield "                <a class=\"breadcrumb__link gin-back-to-admin\" href=\"";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["entity_edit_url"] ?? null), "html", null, true);
                    yield "\">";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Edit %title", ["%title" => ($context["entity_title"] ?? null)]));
                    yield "</a>
              ";
                } else {
                    // line 69
                    yield "                <a class=\"breadcrumb__link gin-back-to-admin\" href=\"";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("system.admin_content"));
                    yield "\">";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Back to Administration"));
                    yield "</a>
              ";
                }
                // line 71
                yield "            </li>
          </ol>
        </nav>
      </div>
    ";
            } else {
                // line 76
                yield "      ";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "breadcrumb", [], "any", false, false, true, 76), "html", null, true);
                yield "
    ";
            }
            // line 78
            yield "    </div>
    ";
            // line 79
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "gin_secondary_toolbar", [], "any", false, false, true, 79), "html", null, true);
            yield "
  </div>

  <div class=\"region-sticky-watcher\"></div>

  <header class=\"region region-sticky ";
            // line 84
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["gin_form_actions_class"] ?? null), "html", null, true);
            yield "\">
    <div class=\"layout-container region-sticky__items\">
      <div class=\"region-sticky__items__inner\">
        ";
            // line 87
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($_v4 = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 87)) && is_array($_v4) || $_v4 instanceof ArrayAccess && in_array($_v4::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v4[(($_v5 = ($context["page_title_block"] ?? null)) instanceof \Stringable ? (string) $_v5 : $_v5)] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 87), ($context["page_title_block"] ?? null), [], "array", false, false, true, 87)), "html", null, true);
            yield "
        ";
            // line 88
            if ( !(($tmp = ($context["active_core_navigation"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 89
                yield "          ";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($_v6 = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 89)) && is_array($_v6) || $_v6 instanceof ArrayAccess && in_array($_v6::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v6[(($_v7 = ($context["local_actions_block"] ?? null)) instanceof \Stringable ? (string) $_v7 : $_v7)] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 89), ($context["local_actions_block"] ?? null), [], "array", false, false, true, 89)), "html", null, true);
                yield "
        ";
            }
            // line 91
            yield "        ";
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["gin_form_actions"] ?? null), "html", null, true);
            yield "
      </div>
    </div>
  </header>

  <div class=\"sticky-shadow\"></div>
";
        }
        // line 98
        yield "
<div class=\"content-header clearfix\">
  <div class=\"layout-container\">
    ";
        // line 101
        yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->withoutFilter(CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 101), ($context["page_title_block"] ?? null)), "html", null, true);
        yield "
  </div>
</div>

<div class=\"layout-container\">
  ";
        // line 106
        yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "pre_content", [], "any", false, false, true, 106), "html", null, true);
        yield "
  <main class=\"page-content clearfix\" role=\"main\">
    <div class=\"visually-hidden\"><a id=\"main-content\" tabindex=\"-1\"></a></div>
    ";
        // line 109
        yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "highlighted", [], "any", false, false, true, 109), "html", null, true);
        yield "
    ";
        // line 110
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "help", [], "any", false, false, true, 110)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 111
            yield "      <div class=\"help\">
        ";
            // line 112
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "help", [], "any", false, false, true, 112), "html", null, true);
            yield "
      </div>
    ";
        }
        // line 115
        yield "    ";
        yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->withoutFilter(CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 115), ($context["local_actions_block"] ?? null)), "html", null, true);
        yield "
  </main>
</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["active_admin_theme", "active_core_navigation", "gin_form_actions_class", "page", "gin_form_actions", "route_name", "entity_edit_url", "entity_title"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/contrib/gin/templates/page/page.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  202 => 115,  196 => 112,  193 => 111,  191 => 110,  187 => 109,  181 => 106,  173 => 101,  168 => 98,  157 => 91,  151 => 89,  149 => 88,  145 => 87,  139 => 84,  131 => 79,  128 => 78,  122 => 76,  115 => 71,  107 => 69,  99 => 67,  97 => 66,  91 => 63,  87 => 61,  85 => 60,  81 => 58,  72 => 53,  66 => 51,  64 => 50,  60 => 49,  53 => 46,  51 => 45,  48 => 44,  46 => 43,  44 => 42,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/contrib/gin/templates/page/page.html.twig", "/Users/sharif/Sites/immofirst/web/themes/contrib/gin/templates/page/page.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["set" => 42, "if" => 45];
        static $filters = ["escape" => 46, "t" => 63, "without" => 101];
        static $functions = ["path" => 69];
        static $tests = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "set", 1 => "if"],
                [0 => "escape", 1 => "t", 2 => "without"],
                [0 => "path"],
                [],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            } elseif ($e instanceof SecurityNotAllowedTestError && isset($tests[$e->getTestName()])) {
                $e->setTemplateLine($tests[$e->getTestName()]);
            }

            throw $e;
        }

    }
}
