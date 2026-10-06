package com.acme.crm.entity.opportunity;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.opportunity.OpportunityDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;

import java.math.BigDecimal;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table("opportunities")
@EqualsAndHashCode(callSuper = true)
public class Opportunity extends BaseAudit {

  @Id
  private Long id;

  private String code;
  private String name;
  private BigDecimal probability;
  private BigDecimal amount;

  private Instant followAt;
  private Instant closeAt;

  private String currency;
  private String cancelReason;

  private String description;
  private String priority;
  private String opportunityStage;
  private Long leadId;
  private Long contactId;
  private Long departmentId;

  public static Opportunity buildCreateFromDTO(OpportunityDTO dto) {
    return Opportunity.builder()
        .code(dto.getCode())
        .name(dto.getName())
        .probability(dto.getProbability())
        .amount(dto.getAmount())
        .currency(dto.getCurrency())
        .priority(dto.getPriority())
        .opportunityStage(String.valueOf(dto.getOpportunityStage()))
        .followAt(dto.getFollowAt())
        .closeAt(dto.getCloseAt())
        .description(dto.getDescription())
        .leadId(dto.getLeadId())
        .contactId(dto.getContactId())
        .departmentId(dto.getDepartmentId())
        .build();
  }

  public static Update buildUpdateFromDTO(OpportunityDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",               dto.getCode());
    addIfNotNull(params, "name",               dto.getName());
    addIfNotNull(params, "probability",        dto.getProbability());
    addIfNotNull(params, "amount",             dto.getAmount());
    addIfNotNull(params, "currency",           dto.getCurrency());
    addIfNotNull(params, "priority",           dto.getPriority());
    addIfNotNull(params, "opportunity_stage",  dto.getOpportunityStage());
    addIfNotNull(params, "follow_at",          dto.getFollowAt());
    addIfNotNull(params, "close_at",           dto.getCloseAt());
    addIfNotNull(params, "cancel_reason",      dto.getCancelReason());
    addIfNotNull(params, "description",        dto.getDescription());
    addIfNotNull(params, "lead_id",            dto.getLeadId());
    addIfNotNull(params, "contact_id",         dto.getContactId());
    addIfNotNull(params, "department_id",      dto.getDepartmentId());
    return Update.from(params);
  }

  public static Update buildUpdateFromDTOV(Opportunity dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",               dto.getCode());
    addIfNotNull(params, "name",               dto.getName());
    addIfNotNull(params, "probability",        dto.getProbability());
    addIfNotNull(params, "amount",             dto.getAmount());
    addIfNotNull(params, "currency",           dto.getCurrency());
    addIfNotNull(params, "priority",           dto.getPriority());
    addIfNotNull(params, "opportunity_stage",  dto.getOpportunityStage());
    addIfNotNull(params, "follow_at",          dto.getFollowAt());
    addIfNotNull(params, "close_at",           dto.getCloseAt());
    addIfNotNull(params, "cancel_reason",      dto.getCancelReason());
    addIfNotNull(params, "description",        dto.getDescription());
    addIfNotNull(params, "lead_id",            dto.getLeadId());
    addIfNotNull(params, "contact_id",         dto.getContactId());
    addIfNotNull(params, "department_id",      dto.getDepartmentId());
    return Update.from(params);
  }

  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) params.put(SqlIdentifier.quoted(field), value);
  }

}