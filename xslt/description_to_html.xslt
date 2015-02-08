<?xml version="1.0"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
  <xsl:template match="/description">
    <HTML>
      <HEAD>
        <TITLE><xsl:value-of select="@of"/></TITLE>
      </HEAD>
      <BODY>
        <H1>
          <xsl:value-of select="@of"/>
       </H1>
       <xsl:for-each select="properties/triple[@p='http://www.w3.org/2004/02/skos/core#prefLabel']">
          <H2><xsl:value-of select="@o"/></H2>
       </xsl:for-each>
       <p>Has the following properties:</p>
       <table>
           <xsl:for-each select="properties/triple">
              <tr>
                 <td><xsl:value-of select="@p"/></td>
                 <td><xsl:choose>
                       <xsl:when test="starts-with(@o,'http://')">
                          <a><xsl:attribute name="href">
                           <xsl:value-of select="concat('describe.vsp?uri=',@o)"/>
                           </xsl:attribute>
                           <xsl:value-of select="@o"/>
                          </a>
                        </xsl:when>
                        <xsl:otherwise>
                           <xsl:value-of select="@o"/>
                        </xsl:otherwise>
                     </xsl:choose>
                  </td>
              </tr>
           </xsl:for-each>
        </table>
        <p>The following individuals reference this:</p>
       <table>
           <xsl:for-each select="references/triple">
              <tr>
                 <td>
                    <a>
                       <xsl:attribute name="href">
                          <xsl:value-of select="concat('/cendari/describe.vsp?uri=',@s)"/>
                        </xsl:attribute>
                        <xsl:value-of select="@s"/>
                     </a>
                  </td>
                 <td><xsl:value-of select="@p"/></td>
              </tr>
           </xsl:for-each>
        </table>
      </BODY>
    </HTML>
  </xsl:template>
  
</xsl:stylesheet>
