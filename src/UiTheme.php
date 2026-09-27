<?php
declare(strict_types=1);

namespace ControlBot\Ui;

final class UiTheme
{
    public static function tokensCss(): string
    {
        return <<<'CSS'
:root {
  color-scheme: dark;
  --bg: #0a0e13;
  --panel: #10161d;
  --panel-raised: #141b23;
  --line: #283440;
  --line-strong: #3b4b58;
  --cyan: #7cc9dd;
  --green: #6fcf9e;
  --amber: #e3a857;
  --red: #e0666f;
  --muted: #95a2ad;
  --text: #edf2f5;
}
CSS;
    }
}
