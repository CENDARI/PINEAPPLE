<?xml version="1.0"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
 <xsl:output indent="no" omit-xml-declaration="yes" method="text" encoding="utf-8"/>
   <xsl:template match="/class_set">
     { "class": "<xsl:value-of select="@class_iri"/>"
     "instances":
       [
         <xsl:for-each select="instances/uri">
            "<xsl:value-of select="text()"/>",
           </xsl:for-each>
        ]
     }
    
  </xsl:template>
  
</xsl:stylesheet>
