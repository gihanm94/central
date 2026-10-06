package com.acme.crm.entity.activity;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.activity.ActivityDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;

import java.time.OffsetDateTime;
import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table("activities")
@EqualsAndHashCode(callSuper = true)
public class Activity extends BaseAudit {

  @Id
  private Long id;

  private String code;
  private String activityType;
  private String topic;

  private Integer callDuration;
  private String callDirection;

  private Integer meetingDuration;
  private String meetingType;
  private String meetingStatus;
  private String meetingLocation;

  private OffsetDateTime startAt;
  private String description;

  private Long leadId;

  private Boolean notifyMe;
  private Integer notifyBefore;

  public static Activity buildCreateFromDTO(ActivityDTO dto) {
    return Activity.builder()
        .code(dto.getCode())
        .activityType(dto.getActivityType())
        .topic(dto.getTopic())
        .callDuration(dto.getCallDuration())
        .callDirection(dto.getCallDirection())
        .meetingDuration(dto.getMeetingDuration())
        .meetingType(dto.getMeetingType())
        .meetingStatus(dto.getMeetingStatus())
        .meetingLocation(dto.getMeetingLocation())
        .startAt(dto.getStartAt())
        .description(dto.getDescription())
        .leadId(dto.getLeadId())
        .notifyMe(dto.getNotifyMe() != null ? dto.getNotifyMe() : Boolean.FALSE)
        .notifyBefore(dto.getNotifyBefore() != null ? dto.getNotifyBefore() : 0)
        .build();
  }

  public static Update buildUpdateFromDTO(ActivityDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",             dto.getCode());
    addIfNotNull(params, "activity_type",    dto.getActivityType());
    addIfNotNull(params, "topic",            dto.getTopic());
    addIfNotNull(params, "call_duration",    dto.getCallDuration());
    addIfNotNull(params, "call_direction",   dto.getCallDirection());
    addIfNotNull(params, "meeting_duration", dto.getMeetingDuration());
    addIfNotNull(params, "meeting_type",     dto.getMeetingType());
    addIfNotNull(params, "meeting_status",   dto.getMeetingStatus());
    addIfNotNull(params, "meeting_location", dto.getMeetingLocation());
    addIfNotNull(params, "start_at",         dto.getStartAt());
    addIfNotNull(params, "description",      dto.getDescription());
    addIfNotNull(params, "lead_id",          dto.getLeadId());
    addIfNotNull(params, "notify_me",        dto.getNotifyMe());
    addIfNotNull(params, "notify_before",    dto.getNotifyBefore());
    return Update.from(params);
  }

  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) params.put(SqlIdentifier.quoted(field), value);
  }
}