package com.acme.crm.entity;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.lead_source.LeadSourceDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;

import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table(	name = "lead_sources")
@EqualsAndHashCode(callSuper = true)
public class LeadSource extends BaseAudit {
  @Id
  private Long id;

  private String name;
  private String color;

  public static LeadSource buildCreateFromDTO(LeadSourceDTO dto) {
    return LeadSource.builder()
        .name(dto.getName())
        .color(dto.getColor())
        .build();
  }

  public static Update buildUpdateFromDTO(LeadSourceDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "name",  dto.getName());
    addIfNotNull(params, "color", dto.getColor());
    return Update.from(params);
  }

  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) {
      params.put(SqlIdentifier.quoted(field), value);
    }
  }
}