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
      .section-social img { filter: invert(1) contrast(0.8) }
    </mj-style>
  </mj-head>
  <mj-body background-color="#ffffff" width="640px">
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
    <mj-section padding="0" background-color="#e6e6e6" css-class="social">    
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
    <mj-section padding="0 20px" background-color="#e6e6e6" css-class="footer">    
        <mj-column padding="0" css-class="section-footer">
            <mj-text color="#000000" font-size="16px" line-height="20px" padding="0">
                <p>
                    Copyright © {$year} FORUM HOLZBAU | <a href="">Impressum</a> | <a href="">Datenschutz</a>
                </p>
                <p>
                    Wenn Sie sich abmelden möchten, klicken Sie auf folgenden Link:
                    <br><a href="#"><b>> Newsletter abmelden</b></a>
                </p>
            </mj-text>
            <mj-text color="#333333" padding="0">
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
    $linkCount = count($links);
    
    // Process links in groups of 4 using table for mobile compatibility
    $result = '';
    
    for ($i = 0; $i < $linkCount; $i += 4) {
      // Build table rows for each group of 4 links
      $tableContent = '<tr>';
      
      // Process up to 4 items for this row
      for ($j = 0; $j < 4; $j++) {
        if ($i + $j < $linkCount) {
          $link = $links[$i + $j];
          $hasImage = !empty($link['imageId']);
          
          if ($hasImage) {
            $imageUrl = $this->getImageUrl($link['imageId']);
            $tableContent .= <<<HTML
            <td style="width: 25%; padding: 0 5px; text-align: center;">
              <a href="{$link['url']}" target="_blank" style="display: block;">
                <img src="{$imageUrl}" alt="{$link['text']}" style="max-width: 100%; height: auto; display: block; margin: 0 auto;" />
              </a>
            </td>
HTML;
          } else {
            $text = str_replace(' ', '<br/>', $link['text']);
            $tableContent .= <<<HTML
            <td style="width: 25%; padding: 0 5px; text-align: center;">
              <div style="border-top: 3px solid #000000; margin-bottom: 10px;"></div>
              <a href="{$link['url']}" target="_blank" style="color: #000000; text-decoration: none; font-weight: 900; font-size: 18px; display: block;">
                {$text}
              </a>
            </td>
HTML;
          }
        } else {
          // Add empty cell to maintain 4-column grid
          $tableContent .= '<td style="width: 25%;"></td>';
        }
      }
      
      $tableContent .= '</tr>';
      
      // Create section with table for this group of 4
      $result .= <<<MJML
    <mj-section padding="0 10px 20px" background-color="#ffffff">
      <mj-column>
        <mj-table>
          {$tableContent}
        </mj-table>
      </mj-column>
    </mj-section>
MJML;
    }
    
    return $result;
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
        $divider = $showDivider ? '<mj-divider padding="0 0 26px" border-width="2px" border-color="#000000" />' : '';

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
          <a href="{$article['link']}" class="link-nostyle" style="background-color: #e6e6e6; padding: 5px 12px; display: inline-block; text-decoration: none;">Mehr lesen</a>
        </mj-text>
      </mj-column>
    </mj-section>
MJML;
      }
    } else {
      // Double column layout - one article per row with image on left (45%) and text on right
      foreach ($articles as $article) {
        $imageUrl = $this->getImageUrl($article['imageId']);
        $text = nl2br($article['text']);

        // Create full-width divider section if enabled
        $dividerSection = '';
        if ($showDivider) {
          $dividerSection = <<<MJML
    <mj-section padding="0 20px" background-color="#ffffff">
      <mj-column>
        <mj-divider padding="0 0 26px" border-width="2px" border-color="#000000" />
      </mj-column>
    </mj-section>
MJML;
        }

        $result .= <<<MJML
{$dividerSection}
    <mj-section padding="0 20px 60px" background-color="#ffffff">
      <mj-column width="45%" padding="0 10px 0 0">
        <mj-image padding="0" src="{$imageUrl}" alt="{$article['title']}" fluid-on-mobile="true" />
      </mj-column>
      <mj-column width="55%" padding="0 0 0 10px">
        <mj-text padding="0 0 20px" font-size="20px" font-weight="bold" color="#000000">
          {$article['title']}
        </mj-text>
        <mj-text padding="0 0 20px" color="#000000">
          {$text}
        </mj-text>
        <mj-text padding="0" color="#000000" font-size="14px">
          <a href="{$article['link']}" class="link-nostyle" style="background-color: #e6e6e6; padding: 5px 12px; display: inline-block; text-decoration: none;">Mehr lesen</a>
        </mj-text>
      </mj-column>
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
    $colorScheme = $section['content']['bgColor'] ?? 'light';
    $eventsList = '';

    $bgColor = '#FFFFFF';
    $textColor = '#000000';
    $divider = '<mj-divider padding="40px 0 0" border-width="1px" border-color="' . $textColor . '" />';

    if ($colorScheme == 'dark') {
      $bgColor = '#000000';
      $textColor = '#FFFFFF';
      $divider = '<mj-divider padding="20px 0 0" border-width="1px" border-color="' .$bgColor . '" />';
    }

    foreach ($events as $event) {
      $eventLink = $event['link'] ?? '';
      
      if (!empty($eventLink)) {
        // Event with link - wrap entire content in link with no visible styling
        $eventsList .= <<<MJML
        <mj-text padding="0 0 8px" color="{$textColor}">
          <a href="{$eventLink}" style="color: inherit; text-decoration: none;">
            <strong>{$event['title']}</strong>
            <br>{$event['date']} | {$event['location']}
          </a>
        </mj-text>
MJML;
      } else {
        // Event without link - display as before
        $eventsList .= <<<MJML
        <mj-text padding="0 0 8px" color="{$textColor}">
          <strong>{$event['title']}</strong>
          <br>{$event['date']} | {$event['location']}
        </mj-text>
MJML;
      }
    }

    return <<<MJML
    <mj-section padding="0 20px" background-color="{$bgColor}">
      <mj-column padding="0 0" >
        <mj-divider padding="0 0 40px" border-width="1px" border-color="{$textColor}" />
        <mj-text padding="0 0 20px"  color="{$textColor}" font-size="24px" font-weight="bold">
          Aktuelle Veranstaltungen von FORUM HOLZBAU
        </mj-text>        
{$eventsList}
         {$divider}
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
      $companyLogoLink = $job['companyLogoLink'] ?? '';
      $jobTextLink = $job['link'] ?? '';

      // Create logo column with optional link
      $logoColumn = '';
      if (!empty($companyLogoLink)) {
        $logoColumn = <<<MJML
      <mj-column width="24%">
        <mj-image src="{$companyLogoUrl}" alt="{$job['company']}" width="150px" href="{$companyLogoLink}" />
      </mj-column>
MJML;
      } else {
        $logoColumn = <<<MJML
      <mj-column width="24%">
        <mj-image src="{$companyLogoUrl}" alt="{$job['company']}" width="150px" />
      </mj-column>
MJML;
      }

      // Create text column with optional link
      $textColumn = '';
      if (!empty($jobTextLink)) {
        $textColumn = <<<MJML
      <mj-column width="70%">
        <mj-text padding="0 0">
          <a href="{$jobTextLink}" class="link-nostyle">
          <strong>{$job['company']}</strong><br />
          <strong>{$job['location']}</strong><br />
          &gt; {$job['title']}</a>
        </mj-text>
      </mj-column>
MJML;
      } else {
        $textColumn = <<<MJML
      <mj-column width="70%">
        <mj-text padding="0 0">
          <strong>{$job['company']}</strong><br />
          <strong>{$job['location']}</strong><br />
          &gt; {$job['title']}
        </mj-text>
      </mj-column>
MJML;
      }

      $jobsList .= <<<MJML
    <mj-section padding="0 0" background-color="#ffffff">
{$logoColumn}
{$textColumn}
    </mj-section>
MJML;

      // Only add divider if this is not the last job
      if ($index < $jobCount - 1) {
        $jobsList .= <<<MJML
    <mj-section background-color="#ffffff">
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
        <mj-divider padding="0" border-width="17px" border-color="#ffffff" />
      </mj-column>
    </mj-section>
{$jobsList}
    <mj-section padding="0" background-color="#ffffff">
      <mj-column padding="0">
        <mj-divider padding="0" border-width="20px" border-color="#ffffff" />
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
    $backgroundColor = $section['content']['backgroundColor'] ?? '#FFFFFF';
    $titleColor = $section['content']['titleColor'] ?? '#000000';
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

          // Create image tag with optional link and subtle border
          if (!empty($url)) {
            $columns .= <<<MJML
      <mj-column padding="0">
        <mj-image padding="0" src="{$logoUrl}" alt="{$partnerName}" href="{$url}" width="120px" background-color="white" padding="10px" border="1px solid #cccccc" />
      </mj-column>
MJML;
          } else {
            $columns .= <<<MJML
      <mj-column padding="0">
        <mj-image padding="0" src="{$logoUrl}" alt="{$partnerName}" width="120px" background-color="white" padding="10px" border="1px solid #cccccc" />
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