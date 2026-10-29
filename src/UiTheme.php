<?php
declare(strict_types=1);

namespace ControlBot\Ui;

final class UiTheme
{
    public static function tokensCss(): string
    {
        return <<<'CSS'
:root {
  color-scheme: light;
  --bg: #f1f7f5; --panel: #ffffff;
  --panel-raised: #e7f1ed; --line: #c8d9d4;
  --line-strong: #9cb5b0; --cyan: #0d5f59;
  --cyan-soft: #0d5f59; --green: #176444;
  --amber: #825008; --red: #a52d3e;
  --muted: #4c6765; --text: #16282c;
  --body-soft: #375956; --field-bg: #f8fbfa;
  --control-hover: #dcece7; --accent-surface: #dcefeb;
  --on-accent: #ffffff; --on-green: #ffffff;
  --warning-bg: #fff1d9; --warning-border: #a16a20;
  --warning-text: #6b4208; --info-border: #0d5f59;
  --rail-width: 244px;
}
@media (prefers-color-scheme: dark) {
  :root {
    color-scheme: dark;
    --bg: #0c1419;
    --panel: #122129;
    --panel-raised: #193039;
    --line: #3c5a63;
    --line-strong: #53727b;
    --cyan: #71ded5;
    --cyan-soft: #9be7e2;
    --green: #8ee6b5;
    --amber: #f4c879;
    --red: #ff9c9f;
    --muted: #a7bcc4;
    --text: #edf7f6;
    --body-soft: #c2d3d5;
    --field-bg: #101d23;
    --control-hover: #254049;
    --accent-surface: #16353a;
    --on-accent: #062324;
    --on-green: #09251b;
    --warning-bg: #302718;
    --warning-border: #c99b59;
    --warning-text: #f4d7a6;
    --info-border: #71ded5;
  }
}
CSS;
    }
}
