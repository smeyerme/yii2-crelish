<?php
/**
 * MJML Newsletter Generator
 * Takes JSON structure and converts it to MJML
 */
namespace giantbits\crelish\components;

use function _\upperCase;

class MjmlGenerator
{
  private $assetManager;
  private $baseUrl;

  public function __construct($assetManager, $baseUrl)
  {
    $this->assetManager = $assetManager;
    $this->baseUrl = $baseUrl;
  }

  /**
   * Generate MJML from newsletter JSON
   */
  public function generateMjml($newsletter)
  {
    $mjml = $this->getTemplateHeader($newsletter);

    foreach ($newsletter['sections'] as $section) {
      $mjml .= $this->renderSection($section);
    }

    $mjml .= $this->getTemplateFooter();

    return $mjml;
  }

  /**
   * Get basic template header with styles
   */
  private function getTemplateHeader($newsletter)
  {
    return <<<MJML
<mjml>
  <mj-head>
    <mj-title>{$newsletter['title']}</mj-title>
    <mj-font name="Helvetica" href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" />
    <mj-attributes>
      <mj-all font-family="Helvetica, Arial, sans-serif" />
      <mj-text font-size="16px" color="#000000" line-height="24px" />
      <mj-section padding="10px" />
    </mj-attributes>
    <mj-style>
      .link-nostyle { color: inherit; text-decoration: none }
      .footer-link { color: #888888 }
      .section-text a, .section-footer a { color: inherit; text-decoration: none }
      .section-text a:hover, .section-footer a:hover { text-decoration: underline }
    </mj-style>
  </mj-head>
  <mj-body background-color="#b5b6b8" width="640px">
MJML;
  }

  /**
   * Render event cards section
   */
  private function renderEventCardSection($section)
  {
    // Extract content properties with defaults
    $title = $section['content']['title'] ?? 'Upcoming Events';
    $backgroundColor = $section['content']['backgroundColor'] ?? '#FFFFFF';
    $events = $section['content']['events'] ?? [];

    // Start with section title
    $result = <<<MJML
    <mj-section padding="20px 0 0" background-color="{$backgroundColor}">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" padding-bottom="10px">
          {$title}
        </mj-text>
      </mj-column>
    </mj-section>
MJML;

    // Ensure we have at most 3 events
    $events = array_slice($events, 0, 3);
    $eventCount = count($events);

    if ($eventCount > 0) {
      // Start a new section for event cards
      $result .= <<<MJML
    <mj-section padding="0 10px 60px" background-color="{$backgroundColor}">
MJML;

      // Add event cards
      foreach ($events as $event) {
        $title = htmlspecialchars($event['title'] ?? '');
        $location = htmlspecialchars($event['location'] ?? '');
        $date = htmlspecialchars($event['date'] ?? '');
        $color = $event['color'] ?? '#006633';
        $link = $event['link'] ?? '';
        $imageUrl = $this->getImageUrl($event['imageId']);

        if(empty($event['imageId'])) {
          $imageUrl = 'https://placehold.co/600x400?text=Event';
        }

        // Column width based on number of events
        $columnWidth = '33.33%';
        if ($eventCount === 1) {
          $columnWidth = '100%';
        } else if ($eventCount === 2) {
          $columnWidth = '50%';
        }

        $result .= <<<MJML
      <mj-column width="{$columnWidth}" padding="0 10px">
        <mj-raw>
          <a href="{$link}" style="text-decoration: none; color: inherit;">
        </mj-raw>
        <mj-divider border-width="3px" border-color="{$color}" padding="0" />
        <mj-text align="left" padding="10px" line-height="22px" font-size="16px" color="{$color}">
          {$location}
          </br>{$date}
        </mj-text>
        <mj-image href="{$link}" src="{$imageUrl}" alt="{$title}" align="left" padding="10px" padding-top="0" />
        <mj-raw>
          </a>
        </mj-raw>
      </mj-column>
MJML;
      }

      // Close the section
      $result .= <<<MJML
    </mj-section>
MJML;
    }

    return $result;
  }

  /**
   * Close MJML template
   */
  private function getTemplateFooter()
  {
    $year = date('Y');

    return <<<MJML
    <mj-section padding="0" background-color="#000000" css-class="social">    
        <mj-column padding="0" css-class="section-social">
          <mj-table width="160px" align="left" padding="20px">
            <tr>
              <td style="width: 60px; padding: 0 10px 0;">
                <a href=https://www.instagram.com/forum_holzbau/" target="_blank"">
                  <img src="https://forum-holzbau.com/uploads/1741114737_fhb-social-insta-png.png" width="60px" alt="Facebook" />
                </a>
              </td>
              <td style="width: 60px; padding: 0 10px 0;">
                <a href="https://www.facebook.com/forumholzbau" target="_blank">
                  <img src="https://forum-holzbau.com/uploads/1741114737_fhb-social-fb-png.png" width="60px" alt="Twitter" />
                </a>
              </td>
              <td style="width: 60px; padding: 0 10px 0;">
                <a href="https://www.linkedin.com/company/forum-holzbau/" target="_blank">
                  <img src="https://forum-holzbau.com/uploads/1741114737_fhb-social-lin-png.png" width="60px" alt="Instagram" />
                </a>
              </td>
            </tr>
          </mj-table>
        </mj-column>
    </mj-section>
    <mj-section padding="0 20px" background-color="#000000" css-class="footer">    
        <mj-column padding="0" css-class="section-footer">
            <mj-text color="#ffffff" font-size="16px" line-height="20px" padding="0">
                <p>
                    Copyright © {$year} FORUM HOLZBAU | <a href="">Impressum</a> | <a href="">Datenschutz</a>
                </p>
                <p>
                    Wenn Sie sich abmelden möchten, klicken Sie auf folgenden Link:
                    <br><a href="#"><b>> Newsletter abmelden</b></a>
                </p>
            </mj-text>
            <mj-text color="#eeeeee" padding="0">
                <p>
                    <a href="#"><b>Oben ^</b></a>
                </p>
            </mj-text>
        </mj-column>
    </mj-section>
  </mj-body>
</mjml>
MJML;
  }

  /**
   * Render a section based on its type
   */
  private function renderSection($section)
  {
    switch ($section['type']) {
      case 'hero':
        return $this->renderHeroSection($section);
      case 'navigation':
        return $this->renderNavigationSection($section);
      case 'article_section':
        return $this->renderArticleSection($section);
      case 'events_list':
        return $this->renderEventsListSection($section);
      case 'job_postings':
        return $this->renderJobPostingsSection($section);
      case 'partners':
        return $this->renderPartnersSection($section);
      case 'ad':
        return $this->renderAdSection($section);
      case 'text':
        return $this->renderTextSection($section);
      case 'event_cards':
        return $this->renderEventCardSection($section);
      default:
        return "<!-- Unknown section type: {$section['type']} -->";
    }
  }

  /**
   * Render hero image section with simplified structure
   * Image at top, text content below for maximum compatibility
   */
  private function renderHeroSection($section)
  {
    // Extract all content properties with defaults
    $imageUrl = $this->getImageUrl($section['content']['imageId']);
    if(empty($section['content']['imageId'])) {
      $imageUrl = 'https://placehold.co/640x480';
    }

    $link = $section['content']['link'] ?? '';
    $title = $section['content']['title'] ?? '';
    $subtitle = $section['content']['subtitle'] ?? '';
    $ctaText = $section['content']['ctaText'] ?? 'Mehr erfahren';
    $titleColor = $section['content']['titleColor'] ?? '#FFFFFF';

    // Start with the main section container
    $heroSection = <<<MJML
    <mj-section padding="20px 20px 40px 20px" background-color="#ffffff">
      <mj-column>
MJML;

    // Add the hero image, with link if provided
    if (!empty($link)) {
      $heroSection .= <<<MJML
        <mj-image src="{$imageUrl}" alt="Hero Image" fluid-on-mobile="true" padding="0" href="{$link}" />
MJML;
    } else {
      $heroSection .= <<<MJML
        <mj-image src="{$imageUrl}" alt="Hero Image" fluid-on-mobile="true" padding="0" />
MJML;
    }

    // If we have text content, add it in a dark background section
    if (!empty($title) || !empty($subtitle)) {
      $heroSection .= <<<MJML
        <mj-section padding="20px 0" background-color="#333333">
          <mj-column>
MJML;

      // Add title if provided
      if (!empty($title)) {
        $heroSection .= <<<MJML
            <mj-text
              align="center"
              font-size="32px"
              font-weight="bold"
              color="{$titleColor}"
              padding="0 20px 10px 20px">
              {$title}
            </mj-text>
MJML;
      }

      // Add subtitle if provided
      if (!empty($subtitle)) {
        $heroSection .= <<<MJML
            <mj-text
              align="center"
              font-size="20px"
              color="{$titleColor}"
              padding="0 20px 20px 20px">
              {$subtitle}
            </mj-text>
MJML;
      }

      // Add CTA button if link is provided
      if (!empty($link)) {
        $heroSection .= <<<MJML
            <mj-button
              href="{$link}"
              align="center"
              background-color="#006633"
              color="white"
              border-radius="4px"
              padding="10px 25px">
              {$ctaText}
            </mj-button>
MJML;
      }

      // Close the text section
      $heroSection .= <<<MJML
          </mj-column>
        </mj-section>
MJML;
    }

    // Close the main section
    $heroSection .= <<<MJML
      </mj-column>
    </mj-section>
MJML;

    return $heroSection;
  }

  /**
   * Render navigation links section
   */
  private function renderNavigationSection($section)
  {
    $links = $section['content']['links'];
    $columns = '';

    foreach ($links as $link) {
      $text = str_replace(' ', '</br>', $link['text']);
      $columns .= <<<MJML
      <mj-column width="50%" padding="0 10px 20px">
          <mj-divider border-width="3px" border-color="#000000" padding="0" />
          <mj-text font-weight="bold" font-size="28px" font-weight="900" padding="10px 0" color="#000000">
              <a href="{$link['url']}" class="link-nostyle" target="_blank">{$text}</a>
          </mj-text>
      </mj-column>
MJML;
    }

    return <<<MJML
    <mj-section padding="0 10px 40px" background-color="#ffffff">
{$columns}
    </mj-section>
MJML;
  }

  /**
   * Render advertisement banner section
   * Displays a full-width clickable banner image
   */
  private function renderAdSection($section)
  {
    // Extract content properties with defaults
    $imageUrl = $this->getImageUrl($section['content']['imageId']);
    if(empty($section['content']['imageId'])) {
      $imageUrl = 'https://placehold.co/640x200?text=Advertisement';
    }

    $url = $section['content']['url'] ?? '';
    $altText = $section['content']['altText'] ?? 'Advertisement';

    // Create the banner with link if URL is provided
    if (!empty($url)) {
      return <<<MJML
    <mj-section padding="0 0 60px" background-color="#ffffff">
      <mj-column padding="0">
        <mj-image src="{$imageUrl}" alt="{$altText}" href="{$url}" fluid-on-mobile="true" padding="0" />
      </mj-column>
    </mj-section>
MJML;
    } else {
      // Display without link if no URL provided
      return <<<MJML
    <mj-section padding="0" background-color="#ffffff">
      <mj-column padding="0">
        <mj-image src="{$imageUrl}" alt="{$altText}" fluid-on-mobile="true" padding="0" />
      </mj-column>
    </mj-section>
MJML;
    }
  }
  /**
   * Render a section with articles
   */
  private function renderArticleSection($section)
  {
    $title = $section['content']['title'] ?? '';
    $layout = $section['content']['layout'] ?? 'single';
    $articles = $section['content']['articles'];
    $articleCount = count($articles);
    $showDivider = ($section['content']['showDivider'] == "false");
    $result = '';

    $title = upperCase($title);

    // Add section title if provided
    if (!empty($title)) {
      $result .= <<<MJML
    <mj-section padding="0 0" background-color="#ffffff">
      <mj-column padding="0">
        <mj-text font-size="24px" font-weight="bold" padding="0 20px 10px">
          {$title}
        </mj-text>
        <mj-divider padding="0 0 30px" border-width="3px" border-color="#000000" />
      </mj-column>
    </mj-section>
MJML;
    }

    // Use layout value to determine how to display articles
    if ($layout === 'single') {
      // Single column layout
      foreach ($articles as $article) {
        $imageUrl = $this->getImageUrl($article['imageId']);
        $text = nl2br($article['text']);

        // Create content with conditional divider
        $divider = $showDivider ? '<mj-divider padding="0 0 10px" border-width="2px" border-color="#000000" />' : '';

        $result .= <<<MJML
    <mj-section padding="0 20px 60px" background-color="#ffffff">
      <mj-column padding="0">
        {$divider}
        <mj-image padding="0 0 20px" src="{$imageUrl}" alt="{$article['title']}" fluid-on-mobile="true" />
        <mj-text padding="0 0 20px" font-size="20px" font-weight="bold" color="#000000">
          {$article['title']}
        </mj-text>
        <mj-text padding="0 0 20px" color="#000000">
          {$text}
        </mj-text>
        <mj-text padding="0" color="#000000" font-size="14px">
          <a href="{$article['link']}" class="link-nostyle">Mehr lesen →</a>
        </mj-text>
      </mj-column>
    </mj-section>
MJML;
      }
    } else {
      // Double column layout
      // Process articles in pairs for 2-column layout
      for ($i = 0; $i < $articleCount; $i += 2) {
        $leftArticle = $articles[$i];
        $leftImageUrl = $this->getImageUrl($leftArticle['imageId']);
        $leftText = nl2br($leftArticle['text']);

        // Create divider HTML if enabled
        $divider = $showDivider ? '<mj-divider padding="0 0 10px" border-width="2px" border-color="#000000" />' : '';

        $rightColumn = '';
        if ($i + 1 < $articleCount) {
          // If there's a second article for this row
          $rightArticle = $articles[$i + 1];
          $rightImageUrl = $this->getImageUrl($rightArticle['imageId']);
          $rightText = nl2br($rightArticle['text']);

          $rightColumn = <<<MJML
      <mj-column padding="0 10px">
        {$divider}
        <mj-image padding="0 0 20px" src="{$rightImageUrl}" alt="{$rightArticle['title']}" fluid-on-mobile="true" />
        <mj-text padding="0 0 20px" font-size="20px" font-weight="bold">
          {$rightArticle['title']}
        </mj-text>
        <mj-text padding="0 0 20px">
          {$rightText}
        </mj-text>
        <mj-text padding="0 0 20px" color="#000000" font-size="14px">
          <a href="{$rightArticle['link']}" class="link-nostyle">Mehr lesen →</a>
        </mj-text>
      </mj-column>
MJML;
        } else {
          // If this is the last article and it's odd-numbered, add an empty column
          $rightColumn = <<<MJML
      <mj-column>
        <!-- Empty column to ensure the last article aligns left -->
      </mj-column>
MJML;
        }

        $result .= <<<MJML
    <mj-section padding="0 10px 40px" background-color="#ffffff">
      <mj-column padding="0 10px">
        {$divider}
        <mj-image padding="0 0 20px" src="{$leftImageUrl}" alt="{$leftArticle['title']}" fluid-on-mobile="true" />
        <mj-text padding="0 0 20px" font-size="20px" font-weight="bold">
          {$leftArticle['title']}
        </mj-text>
        <mj-text padding="0 0 20px">
          {$leftText}
        </mj-text>
        <mj-text padding="0 0 20px" color="#000000" font-size="14px">
          <a href="{$leftArticle['link']}" class="link-nostyle">Mehr lesen →</a>
        </mj-text>
      </mj-column>
{$rightColumn}
    </mj-section>
MJML;
      }
    }

    return $result;
  }

  /**
   * Render text section with formatting support
   */
  private function renderTextSection($section)
  {
    // Extract content properties with defaults
    $text = $section['content']['text'] ?? '';
    $backgroundColor = $section['content']['backgroundColor'] ?? '#FFFFFF';
    $textColor = $section['content']['textColor'] ?? '#000000';

    // Convert line breaks to <br> tags but preserve existing HTML
    $formattedText = nl2br($text);

    return <<<MJML
    <mj-section padding="20px" background-color="{$backgroundColor}">
      <mj-column padding="0" css-class="section-text">
        <mj-divider padding="0 0 40px" border-width="1px" border-color="#ffffff" />
        <mj-text padding="0" color="{$textColor}">
          {$formattedText}
        </mj-text>
      </mj-column>
    </mj-section>
MJML;
  }

  /**
   * Render events list section
   */
  private function renderEventsListSection($section)
  {
    $events = $section['content']['events'];
    $eventsList = '';

    foreach ($events as $event) {
      $eventsList .= <<<MJML
        <mj-text padding="0 0 8px" color="#ffffff">
          <strong>{$event['title']}</strong>
          <br>{$event['date']} | {$event['location']}
        </mj-text>
MJML;
    }

    return <<<MJML
    <mj-section padding="0 20px" background-color="#000000">
      <mj-column padding="0 0" >
        <mj-divider padding="0 0 40px" border-width="1px" border-color="#ffffff" />
        <mj-text padding="0 0 20px"  color="#ffffff" font-size="24px" font-weight="bold">
          Aktuelle Veranstaltungen von FORUM HOLZBAU
        </mj-text>        
{$eventsList}
      </mj-column>
    </mj-section>
MJML;
  }

  /**
   * Render job postings section
   */
  private function renderJobPostingsSection($section)
  {
    $title = htmlspecialchars($section['content']['title'] ?? 'FORUM HOLZKARRIERE');
    $titleColor = $section['content']['titleColor'] ?? '#F7941D';
    $jobs = $section['content']['jobs'];
    $jobsList = '';

    $jobCount = count($jobs);

    foreach ($jobs as $index => $job) {
      $companyLogoUrl = $this->getImageUrl($job['companyLogoId']);

      $jobsList .= <<<MJML
    <mj-section padding="0 0" background-color="#fae9d7">
      <mj-column width="30%">
        <mj-image src="{$companyLogoUrl}" alt="{$job['company']}" width="150px" />
      </mj-column>
      <mj-column width="70%">
        <mj-text>
          <a href="{$job['link']}" class="link-nostyle">
          <strong>{$job['company']}, {$job['location']}</strong><br />
          &gt; {$job['title']}</a>
        </mj-text>
      </mj-column>
    </mj-section>
MJML;

      // Only add divider if this is not the last job
      if ($index < $jobCount - 1) {
        $jobsList .= <<<MJML
    <mj-section background-color="#fae9d7">
      <mj-column>
        <mj-divider border-width="1px" border-color="#000000" />
      </mj-column>
    </mj-section>
MJML;
      }
    }

    return <<<MJML
    <mj-section padding="0" background-color="#ffffff">
      <mj-column padding="0">
        <mj-text font-size="24px" font-weight="bold" color="{$titleColor}">
          {$title}
        </mj-text>
        <mj-divider padding="0" border-width="3px" border-color="#F7941D" />
        <mj-divider padding="0" border-width="17px" border-color="#fae9d7" />
      </mj-column>
    </mj-section>
{$jobsList}
    <mj-section padding="0" background-color="#fae9d7">
      <mj-column padding="0">
        <mj-divider padding="0" border-width="20px" border-color="#fae9d7" />
      </mj-column>
    </mj-section>
    <mj-section padding="0" background-color="#ffffff">
      <mj-column padding="0">
        <mj-divider padding="0" border-width="60px" border-color="#FFFFFF" />
      </mj-column>
    </mj-section>
MJML;
  }

  /**
   * Render partners grid section
   */
  /**
   * Render partners grid section with logos
   */
  private function renderPartnersSection($section)
  {
    // Extract content properties with defaults
    $title = $section['content']['title'] ?? 'Our Partners';
    $backgroundColor = $section['content']['backgroundColor'] ?? '#000000';
    $titleColor = $section['content']['titleColor'] ?? '#FFFFFF';
    $columnCount = $section['content']['columnCount'] ?? 4;
    $partners = $section['content']['partners'] ?? [];

    $result = '';

    // Add the section title
    $result .= <<<MJML
    <mj-section padding="0 20px" background-color="{$backgroundColor}">
      <mj-column padding="0 0 20px">
        <mj-text padding="20px 0 0 0" color="{$titleColor}" font-size="18px" font-weight="bold" padding-bottom="20px">
          {$title}
        </mj-text>
      </mj-column>
    </mj-section>
MJML;

    // Process partners in rows
    for ($i = 0; $i < count($partners); $i += $columnCount) {
      $columns = '';

      for ($j = 0; $j < $columnCount; $j++) {
        if ($i + $j < count($partners)) {
          $partner = $partners[$i + $j];
          $logoUrl = $this->getImageUrl($partner['logoId']);
          $partnerName = $partner['name'] ?? '';
          $url = $partner['url'] ?? '';

          // Create image tag with optional link
          if (!empty($url)) {
            $columns .= <<<MJML
      <mj-column padding="0">
        <mj-image padding="0" src="{$logoUrl}" alt="{$partnerName}" href="{$url}" width="120px" background-color="white" padding="10px" />
      </mj-column>
MJML;
          } else {
            $columns .= <<<MJML
      <mj-column padding="0">
        <mj-image padding="0" src="{$logoUrl}" alt="{$partnerName}" width="120px" background-color="white" padding="10px" />
      </mj-column>
MJML;
          }
        } else {
          // Empty column to maintain grid structure
          $columns .= <<<MJML
      <mj-column></mj-column>
MJML;
        }
      }

      $result .= <<<MJML
    <mj-section padding="0 0 30px" background-color="{$backgroundColor}">
{$columns}
    </mj-section>
MJML;
    }


    return $result;
  }

  /**
   * Get full URL for an image by ID
   */
  private function getImageUrl($imageId)
  {
    if (empty($imageId)) {
      return 'https://placehold.co/640x400';
    }

    return $this->baseUrl . CrelishBaseHelper::getAssetUrlById($imageId);
  }
}