<?xml version="1.0"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
  <xsl:template match="/class_set">
    <HTML>
      <HEAD>
        <TITLE><xsl:value-of select="@class_iri"/></TITLE>
      </HEAD>
      <BODY>
        <H1>
          <xsl:value-of select="@class_iri"/>
        </H1>
        <xsl:apply-templates select="greeter"/>
        <ul>
           <xsl:for-each select="instances/uri">
              <li><a>
                  <xsl:attribute name="href">
                     <xsl:value-of select="concat('/cendari/describe.vsp?uri=',text())"/>  
                  </xsl:attribute>
                  <xsl:value-of select="text()"/>
              </a></li>
           </xsl:for-each>
        </ul>
      </BODY>
    </HTML>
  </xsl:template>
  
</xsl:stylesheet>
