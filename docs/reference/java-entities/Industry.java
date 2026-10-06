package com.acme.crm.entity;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.industry.IndustryDTO;
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
@Table(	name = "industries")
@EqualsAndHashCode(callSuper = true)
public class Industry extends BaseAudit {
  @Id
  private Long id;

  private String name;
  private String color;

  public static Industry buildCreateFromDTO(IndustryDTO dto) {
    return Industry.builder()
        .name(dto.getName())
        .color(dto.getColor())
        .build();
  }

  public static Update buildUpdateFromDTO(IndustryDTO dto) {
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
