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

/* themes/contrib/gin/templates/system/system-themes-page.html.twig */
class __TwigTemplate_70a4edcda86ac77260082b2ad277a291 extends Template
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
        // line 36
        yield "<div";
        yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["attributes"] ?? null), "html", null, true);
        yield ">
  ";
        // line 37
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["theme_groups"] ?? null));
        $context['loop'] = [
          'parent' => $context['_parent'],
          'index0' => 0,
          'index'  => 1,
          'first'  => true,
        ];
        if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
            $length = count($context['_seq']);
            $context['loop']['revindex0'] = $length - 1;
            $context['loop']['revindex'] = $length;
            $context['loop']['length'] = $length;
            $context['loop']['last'] = 1 === $length;
        }
        foreach ($context['_seq'] as $context["_key"] => $context["theme_group"]) {
            // line 38
            yield "    ";
            // line 39
            $context["theme_group_classes"] = ["system-themes-list", ("system-themes-list--" . CoreExtension::getAttribute($this->env, $this->source,             // line 41
$context["theme_group"], "state", [], "any", false, false, true, 41)), "clearfix", "gin-layer-wrapper"];
            // line 46
            yield "
    ";
            // line 48
            $context["card_alignment"] = (((CoreExtension::getAttribute($this->env, $this->source, $context["theme_group"], "state", [], "any", false, false, true, 48) == "installed")) ? ("horizontal") : ("vertical"));
            // line 50
            yield "
    ";
            // line 51
            if ( !(($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "first", [], "any", false, false, true, 51)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 52
                yield "      <hr>
    ";
            }
            // line 54
            yield "
    <div";
            // line 55
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["theme_group"], "attributes", [], "any", false, false, true, 55), "addClass", [($context["theme_group_classes"] ?? null)], "method", false, false, true, 55), "html", null, true);
            yield ">
      <h2 class=\"system-themes-list__header\">";
            // line 56
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme_group"], "title", [], "any", false, false, true, 56), "html", null, true);
            yield "</h2>
      <div class=\"card-list ";
            // line 57
            yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar((((($context["card_alignment"] ?? null) == "horizontal")) ? ("card-list--two-cols") : ("card-list--four-cols")));
            yield "\">
        ";
            // line 58
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["theme_group"], "themes", [], "any", false, false, true, 58));
            foreach ($context['_seq'] as $context["_key"] => $context["theme"]) {
                // line 59
                yield "          ";
                // line 60
                $context["theme_classes"] = [(((($tmp = CoreExtension::getAttribute($this->env, $this->source,                 // line 61
$context["theme"], "is_default", [], "any", false, false, true, 61)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("theme-default") : ("")), (((($tmp = CoreExtension::getAttribute($this->env, $this->source,                 // line 62
$context["theme"], "is_admin", [], "any", false, false, true, 62)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("theme-admin") : ("")), "card", ("card--" .                 // line 64
($context["card_alignment"] ?? null)), "card-list__item"];
                // line 68
                yield "          ";
                // line 69
                $context["theme_title_classes"] = ["card__content-item", "heading-f"];
                // line 74
                yield "          ";
                // line 75
                $context["theme_description_classes"] = ["card__content-item"];
                // line 79
                yield "          <div";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "attributes", [], "any", false, false, true, 79), "addClass", [($context["theme_classes"] ?? null)], "method", false, false, true, 79), "setAttribute", ["aria-labelledby", CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "title_id", [], "any", false, false, true, 79)], "method", false, false, true, 79), "setAttribute", ["aria-describedby", (((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description_id", [], "any", false, false, true, 79)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? (CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description_id", [], "any", false, false, true, 79)) : (null))], "method", false, false, true, 79), "html", null, true);
                yield ">
            ";
                // line 80
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "screenshot", [], "any", false, false, true, 80)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 81
                    yield "              <div class=\"card__image\">
                ";
                    // line 82
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "screenshot", [], "any", false, false, true, 82), "html", null, true);
                    yield "
              </div>
            ";
                }
                // line 85
                yield "            <div class=\"card__content-wrapper\">
              <div class=\"card__content\">
                <h3";
                // line 87
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(["id" => CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "title_id", [], "any", false, false, true, 87)]), "addClass", [($context["theme_title_classes"] ?? null)], "method", false, false, true, 87), "html", null, true);
                yield " id=";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "title_id", [], "any", false, false, true, 87), "html", null, true);
                yield ">";
                // line 88
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "name", [], "any", false, false, true, 88), "html", null, true);
                yield " ";
                yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "version", [], "any", false, false, true, 88), "html", null, true);
                // line 89
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "notes", [], "any", false, false, true, 89)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 90
                    yield "                    (";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->safeJoin($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "notes", [], "any", false, false, true, 90), ", "));
                    yield ")";
                }
                // line 92
                yield "</h3>

                ";
                // line 94
                if (((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description", [], "any", false, false, true, 94)) && $tmp instanceof Markup ? (string) $tmp : $tmp) && (($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description_id", [], "any", false, false, true, 94)) && $tmp instanceof Markup ? (string) $tmp : $tmp))) {
                    // line 95
                    yield "                  <div";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(["id" => (((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description_id", [], "any", false, false, true, 95)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? (CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description_id", [], "any", false, false, true, 95)) : (null))]), "addClass", [($context["theme_description_classes"] ?? null)], "method", false, false, true, 95), "html", null, true);
                    yield ">
                    ";
                    // line 96
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "description", [], "any", false, false, true, 96), "html", null, true);
                    yield "
                  </div>";
                }
                // line 99
                yield "</div>

              <div class=\"card__footer\">
                ";
                // line 102
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "module_dependencies", [], "any", false, false, true, 102)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 103
                    yield "                  <div class=\"theme-info__requires\">
                    ";
                    // line 104
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Requires: @module_dependencies", ["@module_dependencies" => $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "module_dependencies", [], "any", false, false, true, 104))]));
                    yield "
                  </div>
                ";
                }
                // line 107
                yield "                ";
                // line 108
                yield "                ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "incompatible", [], "any", false, false, true, 108)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 109
                    yield "                  <small class=\"incompatible\">";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "incompatible", [], "any", false, false, true, 109), "html", null, true);
                    yield "</small>
                ";
                } else {
                    // line 111
                    yield "                    ";
                    yield (string) $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["theme"], "operations", [], "any", false, false, true, 111), "html", null, true);
                    yield "
                ";
                }
                // line 113
                yield "              </div>
            </div>
          </div>
        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['theme'], $context['_parent']);
            $context = array_intersect_key($context, $_parent);
            $context += $_parent;
            // line 117
            yield "      </div>
    </div>
  ";
            ++$context['loop']['index0'];
            ++$context['loop']['index'];
            $context['loop']['first'] = false;
            if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                --$context['loop']['revindex0'];
                --$context['loop']['revindex'];
                $context['loop']['last'] = 0 === $context['loop']['revindex0'];
            }
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['theme_group'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent);
        $context += $_parent;
        // line 120
        yield "</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["attributes", "theme_groups", "loop"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/contrib/gin/templates/system/system-themes-page.html.twig";
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
        return array (  231 => 120,  214 => 117,  204 => 113,  198 => 111,  192 => 109,  189 => 108,  187 => 107,  181 => 104,  178 => 103,  176 => 102,  171 => 99,  166 => 96,  161 => 95,  159 => 94,  155 => 92,  150 => 90,  148 => 89,  144 => 88,  139 => 87,  135 => 85,  129 => 82,  126 => 81,  124 => 80,  119 => 79,  117 => 75,  115 => 74,  113 => 69,  111 => 68,  109 => 64,  108 => 62,  107 => 61,  106 => 60,  104 => 59,  100 => 58,  96 => 57,  92 => 56,  88 => 55,  85 => 54,  81 => 52,  79 => 51,  76 => 50,  74 => 48,  71 => 46,  69 => 41,  68 => 39,  66 => 38,  49 => 37,  44 => 36,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/contrib/gin/templates/system/system-themes-page.html.twig", "/Users/sharif/Sites/immofirst/web/themes/contrib/gin/templates/system/system-themes-page.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["for" => 37, "set" => 39, "if" => 51];
        static $filters = ["escape" => 36, "safe_join" => 90, "t" => 104, "render" => 104];
        static $functions = ["create_attribute" => 87];
        static $tests = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "for", 1 => "set", 2 => "if"],
                [0 => "escape", 1 => "safe_join", 2 => "t", 3 => "render"],
                [0 => "create_attribute"],
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
