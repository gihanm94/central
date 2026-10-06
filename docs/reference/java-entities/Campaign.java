package com.acme.crm.entity.campaign;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.campaign.CampaignDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;
import org.springframework.util.StringUtils;

import java.math.BigDecimal;
import java.time.OffsetDateTime;
import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table("campaigns")
@EqualsAndHashCode(callSuper = true)
public class Campaign extends BaseAudit {

  @Id
  private Long id;

  private String code;
  private String name;
  private String type;
  private String status;

  private OffsetDateTime startDate;
  private OffsetDateTime endDate;

  private BigDecimal budget;
  private BigDecimal actualCost;
  private BigDecimal expectedRevenue;
  private BigDecimal expectedResponseRate;

  private BigDecimal actualResponseRate;
  private String description;
  private Long departmentId;

  public static Campaign buildCreateFromDTO(CampaignDTO dto) {
    return Campaign.builder()
        .code(dto.getCode())
        .name(dto.getName())
        .type(dto.getType())
        .status(StringUtils.hasText(dto.getStatus()) ? dto.getStatus() : "DRAFT")
        .startDate(dto.getStartDate())
        .endDate(dto.getEndDate())
        .budget(dto.getBudget())
        .actualCost(dto.getActualCost())
        .expectedRevenue(dto.getExpectedRevenue())
        .expectedResponseRate(dto.getExpectedResponseRate())
        .actualResponseRate(dto.getActualResponseRate())
        .description(dto.getDescription())
        .departmentId(dto.getDepartmentId())
        .build();
  }

  public static Update buildUpdateFromDTO(CampaignDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",                   dto.getCode());
    addIfNotNull(params, "name",                   dto.getName());
    addIfNotNull(params, "type",                   dto.getType());
    addIfNotNull(params, "status",                 dto.getStatus());
    addIfNotNull(params, "start_date",             dto.getStartDate());
    addIfNotNull(params, "end_date",               dto.getEndDate());
    addIfNotNull(params, "budget",                 dto.getBudget());
    addIfNotNull(params, "actual_cost",            dto.getActualCost());
    addIfNotNull(params, "expected_revenue",       dto.getExpectedRevenue());
    addIfNotNull(params, "expected_response_rate", dto.getExpectedResponseRate());
    addIfNotNull(params, "actual_response_rate",   dto.getActualResponseRate());
    addIfNotNull(params, "description",            dto.getDescription());
    addIfNotNull(params, "department_id",          dto.getDepartmentId());
    return Update.from(params);
  }

  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) params.put(SqlIdentifier.quoted(field), value);
  }
}
